<?php

namespace App\Controllers;

use App\ChatManager;
use App\Cache;
use App\Enums\Action;
use App\Enums\Tab;
use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\MemorySelector;

class ChatController
{
    private $db;
    private $memoryRepository;
    private $chatSessionRepository;
    private $agentManager;
    private $memoryExtractor;
    private $envEditor;
    private $status;

    public function __construct(
        $db,
        MemoryRepository $memoryRepository,
        ChatSessionRepository $chatSessionRepository,
        $agentManager,
        $memoryExtractor,
        $envEditor,
        $status
    ) {
        $this->db = $db;
        $this->memoryRepository = $memoryRepository;
        $this->chatSessionRepository = $chatSessionRepository;
        $this->agentManager = $agentManager;
        $this->memoryExtractor = $memoryExtractor;
        $this->envEditor = $envEditor;
        $this->status = $status;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handlePost();
        } elseif ($method === 'GET') {
            $this->handleGet();
        }
    }

    private function handlePost(): void
    {
        if ($this->isApiRequest()) {
            $this->handleApiPost();
            return;
        }

        $sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
        $activeTab = Tab::tryFrom($_POST['tab'] ?? $_GET['tab'] ?? '') ?? Tab::CHATS;
        $action = $this->resolvePostAction();

        if ($action === null) {
            $this->redirect($this->buildUrl($sessionId, $activeTab));
            return;
        }

        switch ($action) {
            case Action::SAVE_SETTINGS:
                $this->saveSettings($sessionId, $activeTab);
                break;

            case Action::MANUAL_CONSOLIDATE:
                $this->manualConsolidate($sessionId);
                break;

            case Action::ADD_MEMORY:
                $this->addMemory($sessionId);
                break;

            case Action::DELETE_MEMORY:
                $this->deleteMemory($sessionId);
                break;

            case Action::DELETE_MULTIPLE_MEMORIES:
                $this->deleteMultipleMemories($sessionId);
                break;

            case Action::DELETE_MULTIPLE_SESSIONS:
                $this->deleteMultipleSessions();
                break;

            case Action::UPDATE_MEMORY:
                $this->updateMemory($sessionId);
                break;

            case Action::DELETE_QUERY:
                $this->deleteQuery($sessionId);
                break;

            case Action::CLEAR_ALL:
                $this->clearAllData();
                break;
        }
    }

    private function handleGet(): void
    {
        $newChat = $_GET['new_chat'] ?? '';
        $deleteSessionId = (int)($_GET['delete_session'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;

        if ($this->db && $deleteSessionId > 0) {
            $this->chatSessionRepository->delete($deleteSessionId);
            $this->redirect("index.php");
            return;
        }

        if ($this->db && $newChat === '1') {
            $newId = $this->chatSessionRepository->create('New Conversation');
            $this->redirect($this->buildUrl($newId, $activeTab));
            return;
        }
    }

    private function handleApiPost(): void
    {
        if (!$this->status->all_operational || !$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'System offline.'], 503);
            return;
        }

        $action = Action::tryFrom($_POST['action'] ?? '');

        if ($action === Action::CONDENSE) {
            $this->handleCondenseAction();
            return;
        }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $query = $_POST['q'] ?? '';
        $imageFile = $_FILES['file'] ?? $_FILES['image'] ?? null;
        $cacheAction = $_POST['cache_action'] ?? null;
        $cacheKey = $_POST['cache_key'] ?? null;

        if (empty($query) && empty($imageFile) && empty($cacheAction)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Empty prompt.'], 400);
            return;
        }

        $this->streamChatResponse($sessionId, $query, $imageFile, $cacheAction, $cacheKey);
    }

    private function handleCondenseAction(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid session ID.'], 400);
            return;
        }

        try {
            $condenser = new ContextCondenser($this->agentManager);
            $result = $condenser->condenseChatHistory($this->db, $sessionId);
            $this->jsonResponse($result);
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function streamChatResponse(int $sessionId, string $query, $imageFile, $cacheAction, $cacheKey): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @set_time_limit(600);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $pad = str_repeat(" ", 4096);
        echo ":{$pad}\n\n";
        @flush();

        $searchDecider = new SearchDecider($this->agentManager);
        $cacheEvaluator = new SemanticCacheEvaluator($this->agentManager);
        $contextCondenser = new ContextCondenser($this->agentManager);
        $memorySelectorInstance = $this->db ? new MemorySelector($this->db, $this->agentManager) : null;

        $chatManager = new ChatManager(
            $this->db,
            $this->agentManager,
            $this->memoryExtractor,
            $memorySelectorInstance,
            $searchDecider,
            $cacheEvaluator,
            $contextCondenser
        );

        $chatManager->process($sessionId, $query, $imageFile, $cacheAction, $cacheKey, function($event, $data) {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        });
    }

    private function saveSettings(int $sessionId, Tab $activeTab): void
    {
        $currentEnv = $this->envEditor->read();
        $newEnv = [];

        foreach (array_keys($currentEnv) as $key) {
            if (isset($_POST[$key])) {
                $newEnv[$key] = $_POST[$key];
            }
        }

        $this->envEditor->write($newEnv);
        $this->redirect($this->buildUrl($sessionId, $activeTab));
    }

    private function manualConsolidate(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $chatText = "";
        if ($sessionId > 0) {
            $history = $this->chatSessionRepository->getHistory($sessionId);
            foreach ($history as $row) {
                $role = ucfirst($row['role']);
                $message = $row['message'];
                $chatText .= "{$role}: {$message}\n\n";
            }
        }

        $this->memoryExtractor->extractAndSave($chatText);
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function addMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $count = $this->memoryRepository->getCount();

        if ($count < 500) {
            $text = trim($_POST['memory_text'] ?? '');
            if (!empty($text)) {
                $this->memoryRepository->add($text);
            }
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $memoryId = (int)($_POST['memory_id'] ?? 0);
        $this->memoryRepository->delete($memoryId);
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteMultipleMemories(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $selectedIds = $_POST['selected_memories'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->memoryRepository->deleteMultiple($selectedIds);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteMultipleSessions(): void
    {
        if (!$this->db) {
            $this->redirect("index.php");
            return;
        }

        $selectedIds = $_POST['selected_sessions'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->chatSessionRepository->deleteMultiple($selectedIds);
        }
        $this->redirect("index.php");
    }

    private function updateMemory(int $sessionId): void
    {
        if (!$this->db) {
            $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
            return;
        }

        $memoryId = (int)($_POST['memory_id'] ?? 0);
        $text = trim($_POST['memory_text'] ?? '');
        if (!empty($text)) {
            $this->memoryRepository->update($memoryId, $text);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::MEMORIES));
    }

    private function deleteQuery(int $sessionId): void
    {
        if (!$this->status->redis) {
            $this->redirect($this->buildUrl($sessionId, Tab::QUERIES));
            return;
        }

        $cacheKey = $_POST['cache_key'] ?? '';
        if (!empty($cacheKey)) {
            $ledger = Cache::getSearchLedger();
            $filteredLedger = array_filter($ledger, fn($item) => $item['cache_key'] !== $cacheKey);
            Cache::set('search_ledger', json_encode(array_values($filteredLedger)), 604800);
            Cache::delete($cacheKey);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::QUERIES));
    }

    private function clearAllData(): void
    {
        if ($this->db) {
            $this->chatSessionRepository->truncateAll();
        }
        $this->redirect("index.php");
    }

    private function resolvePostAction(): ?Action
    {
        $actionValue = $_POST['action'] ?? null;
        if ($actionValue !== null) {
            return Action::tryFrom($actionValue);
        }

        foreach (Action::cases() as $action) {
            if (isset($_POST[$action->value])) {
                return $action;
            }
        }

        return null;
    }

    private function isApiRequest(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($acceptHeader, 'application/json') !== false || strpos($acceptHeader, 'text/event-stream') !== false;
    }

    private function buildUrl(int $sessionId, Tab $tab): string
    {
        return "index.php?session_id=" . $sessionId . "&tab=" . $tab->value;
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function redirect(string $url): void
    {
        header("Location: " . $url);
        exit;
    }
}