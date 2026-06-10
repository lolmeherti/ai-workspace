<?php

namespace App\Controllers;

use App\ChatManager;
use App\Enums\Action;
use App\Enums\ApiAction;
use App\Enums\Tab;
use App\Repositories\ChatSessionRepository;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\MemorySelector;

class ChatController extends BaseController
{
    private $db;
    private $chatSessionRepository;
    private $agentManager;
    private $memoryExtractor;
    private $status;

    public function __construct($db, ChatSessionRepository $chatSessionRepository, $agentManager, $memoryExtractor, $status)
    {
        $this->db = $db;
        $this->chatSessionRepository = $chatSessionRepository;
        $this->agentManager = $agentManager;
        $this->memoryExtractor = $memoryExtractor;
        $this->status = $status;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }

    private function handlePost(): void
    {
        if ($this->isApiRequest()) {
            $this->handleApiPost();
            return;
        }

        $action = $_POST['action'] ?? null;
        if ($action === Action::DELETE_MULTIPLE_SESSIONS->value || isset($_POST[Action::DELETE_MULTIPLE_SESSIONS->value])) {
            $this->deleteMultipleSessions();
        }
    }

    private function handleGet(): void
    {
        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);
        
        if ($apiAction === ApiAction::DELETE_TODOIST_TASK) {
            $this->handleDeleteTodoistTask();
            return;
        }

        $newChat = $_GET['new_chat'] ?? '';
        $deleteSessionId = (int)($_GET['delete_session'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;
        $sessionId = (int)($_GET['session_id'] ?? 0);

        if ($this->db && $deleteSessionId > 0) {
            $this->chatSessionRepository->delete($deleteSessionId);
            $this->redirect("index.php?new_chat=1");
            return;
        }

        if ($this->db && $newChat === '1') {
            $newId = $this->chatSessionRepository->create('New Conversation');
            $this->redirect($this->buildUrl($newId, $activeTab));
            return;
        }

        if ($this->db && isset($_GET['toggle_star'])) {
            $this->handleToggleStar();
            return;
        }

        if ($this->db && $sessionId === 0) {
            $sessions = $this->chatSessionRepository->getAllDesc();
            if (!empty($sessions)) {
                $mostRecentId = (int)$sessions[0]['id'];
                $this->redirect($this->buildUrl($mostRecentId, $activeTab));
                return;
            } else {
                $this->redirect("index.php?new_chat=1");
                return;
            }
        }
    }

    private function handleDeleteTodoistTask(): void
    {
        $taskId = $_GET['task_id'] ?? '';
        if (empty($taskId)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing task ID.'], 400);
            return;
        }

        try {
            $uploadDir = \App\Config::getProjectRoot() . '/uploads/';
            
            $toolService = new \App\Services\ToolExecutionService($this->db, $this->agentManager, $uploadDir);
            
            $toolService->makeTodoistRequest('DELETE', '/tasks/' . $taskId);
            
            $this->jsonResponse([
                'status' => 'success', 
                'message' => 'Task successfully deleted from Todoist.'
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'status' => 'error', 
                'message' => $e->getMessage()
            ], 500);
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

    private function handleToggleStar(): void
    {
        $starSessionId = (int)($_GET['toggle_star'] ?? 0);
        $activeTab = Tab::tryFrom($_GET['tab'] ?? '') ?? Tab::CHATS;

        if ($starSessionId > 0) {
            $isStarred = $this->chatSessionRepository->toggleStar($starSessionId);
            
            $isAjax = (isset($_GET['ajax']) && $_GET['ajax'] == 1) || $this->isApiRequest();
            if ($isAjax) {
                $this->jsonResponse(['status' => 'success', 'is_starred' => $isStarred]);
                return;
            }
            
            $redirectSessionId = (int)($_GET['session_id'] ?? $starSessionId);
            $this->redirect($this->buildUrl($redirectSessionId, $activeTab));
            return;
        }
    }

    private function handleCondenseAction(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid session ID.'], 400);
            return;
        }

        $commit = ($_POST['commit'] ?? '0') === '1';
        $manual = ($_POST['manual'] ?? '0') === '1';

        try {
            $condenser = new ContextCondenser($this->agentManager);
            if ($commit) {
                $summary = $_POST['summary'] ?? '';
                $selectedMemories = $_POST['selected_memories'] ?? [];
                if (!is_array($selectedMemories)) {
                    $selectedMemories = [];
                }
                $result = $condenser->commitCondensation($this->db, $sessionId, $summary, $selectedMemories, $manual);
            } else {
                $result = $condenser->generateCondensationPreview($this->db, $sessionId, $manual);
            }
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

    private function deleteMultipleSessions(): void
    {
        if (!$this->db) {
            $this->redirect("index.php?new_chat=1");
            return;
        }

        $selectedIds = $_POST['selected_sessions'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $selectedIds = array_values(array_map('intval', $selectedIds));
            $this->chatSessionRepository->deleteMultiple($selectedIds);
        }
        $this->redirect("index.php?new_chat=1");
    }
}