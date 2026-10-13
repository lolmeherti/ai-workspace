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
use App\Actions\Chat\ContextDataToggleAction;
use App\Actions\Chat\ContextDataViewAction;
use App\Actions\Chat\ContextDataAtomizeAction;
use App\Actions\Chat\StopTurnAction;
use App\Actions\Chat\ChatExportAction;

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

        // Scope every event logged in this request to the ambient chat session
        // (chat turns, condense, briefing, star/toggle actions all read session_id).
        $ambientSessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
        \App\Logger::setSessionId($ambientSessionId > 0 ? $ambientSessionId : null);

        // The stop control is handled here, before the request is split into "page" and "API" shapes: the client posts
        // JSON (`Accept: application/json`) while a curl or an older form post may not, and a stop must not depend on
        // which branch the request happens to take. It has to run before the chat path's empty-prompt check, or a stop
        // would be answered with "Empty prompt." (learned the hard way).
        if ($method === 'POST' && ($_POST['action'] ?? '') === Action::STOP_TURN->value) {
            (new StopTurnAction($this->db))->execute();
            return;
        }

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

        if ($action === Action::ATOMIZE_CONTEXT->value) {
            (new ContextDataAtomizeAction($this->db, $this->agentManager))->execute();
            return;
        }
    }

    private function handleGet(): void
    {
        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);

        if ($apiAction === ApiAction::EXPORT_CONVERSATION) {
            (new ChatExportAction($this->chatSessionRepository, $this->db))->execute();
            return;
        }

        if ($apiAction === ApiAction::DELETE_CALENDAR_TASK) {
            (new ChatTodoistDeleteAction($this->db, $this->agentManager))->execute();
            return;
        }

        if ($apiActionVal === ApiAction::CREATE_CALENDAR_TASK->value) {
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
            $newId = $this->chatSessionRepository->findEmptySessionId()
                ?? $this->chatSessionRepository->create('New Conversation');
            $this->redirect($this->buildUrl($newId, $activeTab));
            return;
        }

        if ($this->db && isset($_GET['toggle_star'])) {
            (new ChatStarAction($this->chatSessionRepository))->execute();
            return;
        }

        if ($this->db && isset($_GET['toggle_context'])) {
            (new ContextDataToggleAction($this->db))->execute();
            return;
        }

        if ($this->db && isset($_GET['view_context'])) {
            (new ContextDataViewAction($this->db))->execute();
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
        $activeEditFile = $_POST['active_edit_file'] ?? null;
        $effort = $_POST['effort'] ?? null;

        if (empty($query) && empty($imageFile)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Empty prompt.'], 400);
            return;
        }

        $cleanQuery = trim($query);
        
        if (str_starts_with($cleanQuery, "[TRIGGER_BRIEFING_PIPELINE]")) {
            $includeSeen = str_contains($cleanQuery, ":include_read");
            (new ChatBriefingStreamAction($this->db, $this->agentManager))->execute($sessionId, $includeSeen);
            return;
        }

        // The composer's Parse links control: '0' is the user deciding this message's links stay closed, which is
        // a decision — not the same as a client that sent no choice at all. Absent keeps the app's own link rule
        // (a message that is essentially just a link is read). The backend does the URL work either way: nothing
        // is enumerated in the browser. An explicit per-URL list is still honoured if some caller sends one.
        $fetchUrls = null;
        if (isset($_POST['fetch_urls'])) {
            $decoded = json_decode((string) $_POST['fetch_urls'], true);
            $fetchUrls = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        } elseif ((string) ($_POST['parse_links'] ?? '1') === '0') {
            $fetchUrls = [];
        }

        (new ChatStreamAction($this->db, $this->agentManager))
            ->execute($sessionId, $query, $imageFile, null, null, $activeEditFile, $effort, $fetchUrls);
    }
}
