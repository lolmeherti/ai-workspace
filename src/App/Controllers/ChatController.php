<?php

namespace App\Controllers;

use App\Enums\Action;
use App\Enums\ApiAction;
use App\Enums\Tab;
use App\Repositories\ChatSessionRepository;
use App\Actions\Chat\ChatTodoistCreateAction;
use App\Actions\Chat\ChatTodoistDeleteAction;
use App\Actions\Chat\ChatReplyAssistAction;
use App\Actions\Chat\ChatBriefingStreamAction;
use App\Actions\Chat\ChatStarAction;
use App\Actions\Chat\ChatCondenseAction;
use App\Actions\Chat\ChatStreamAction;
use App\Actions\Chat\ChatSessionDeleteAction;

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
            (new ChatSessionDeleteAction($this->db, $this->chatSessionRepository))->execute();
        }
    }

    private function handleGet(): void
    {
        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);

        if ($apiAction === ApiAction::DELETE_TODOIST_TASK) {
            (new ChatTodoistDeleteAction($this->db, $this->agentManager))->execute();
            return;
        }

        if ($apiActionVal === ApiAction::CREATE_TODOIST_TASK->value) {
            (new ChatTodoistCreateAction($this->db, $this->agentManager))->execute();
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
            (new ChatStarAction($this->chatSessionRepository))->execute();
            return;
        }

        if ($this->db && $sessionId === 0) {
            $sessions = $this->chatSessionRepository->getAllDesc();
            if (!empty($sessions)) {
                $mostRecentId = (int)$sessions[0]['id'];
                $this->redirect($this->buildUrl($mostRecentId, $activeTab));
                return;
            }
            $this->redirect("index.php?new_chat=1");
        }
    }

    private function handleApiPost(): void
    {
        if (!$this->status->all_operational || !$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'System offline.'], 503);
            return;
        }

        $actionVal = $_POST['action'] ?? '';

        if ($actionVal === 'ai_reply_assist') {
            (new ChatReplyAssistAction($this->agentManager))->execute();
            return;
        }

        $action = Action::tryFrom($actionVal);

        if ($action === Action::CONDENSE) {
            (new ChatCondenseAction($this->db, $this->agentManager))->execute();
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

        $cleanQuery = trim($query);
        if (str_starts_with($cleanQuery, "[TRIGGER_BRIEFING_PIPELINE]")) {
            $includeSeen = str_contains($cleanQuery, ":include_read");
            (new ChatBriefingStreamAction($this->db, $this->agentManager))->execute($sessionId, $includeSeen);
            return;
        }

        (new ChatStreamAction($this->db, $this->agentManager, $this->memoryExtractor))
            ->execute($sessionId, $query, $imageFile, $cacheAction, $cacheKey);
    }
}
