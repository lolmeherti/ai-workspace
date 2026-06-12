<?php

namespace App\Routing;

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Controllers\ChatController;
use App\Controllers\MemoryController;
use App\Controllers\AISettingsController;
use App\Controllers\FileController;
use App\Controllers\CacheController;
use App\Controllers\EmailController;
use App\Enums\Action;
use App\Enums\ApiAction;

class ActionRouter
{
    public function __construct(
        private $db,
        private MemoryRepository $memoryRepository,
        private ChatSessionRepository $chatSessionRepository,
        private $memoryExtractor,
        private $agentManager,
        private $status,
        private $envEditor
    ) {
    }

    public function route(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $postActionVal = $_POST['action'] ?? null;
        $postAction = null;
        if ($postActionVal !== null) {
            $postAction = Action::tryFrom($postActionVal);
        } else {
            foreach (Action::cases() as $case) {
                if (isset($_POST[$case->value])) {
                    $postAction = $case;
                    break;
                }
            }
        }

        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);

        $isFileAction = (
            $apiAction === ApiAction::SHOW_IN_EXPLORER ||
            $apiAction === ApiAction::GET_FILE_CONTENT ||
            $apiAction === ApiAction::SEARCH_FILES ||
            $apiAction === ApiAction::OPEN_DRAFT ||
            $apiAction === ApiAction::UPDATE_DRAFT ||
            $apiAction === ApiAction::SAVE_DRAFT ||
            $apiAction === ApiAction::DISCARD_DRAFT ||
            $apiAction === ApiAction::DELETE_DRAFT_BLOCKS ||
            $apiAction === ApiAction::SYNC_FILES ||
            $apiAction === ApiAction::UPLOAD_FILE
        );

        if ($isFileAction) {
            $controller = new FileController();
            $controller->setDatabase($this->db);
        } elseif ($method === 'POST') {
            if ($postAction !== null) {
                switch ($postAction) {
                    case Action::MANUAL_CONSOLIDATE:
                    case Action::ADD_MEMORY:
                    case Action::DELETE_MEMORY:
                    case Action::DELETE_MULTIPLE_MEMORIES:
                    case Action::UPDATE_MEMORY:
                        $controller = new MemoryController($this->db, $this->memoryRepository, $this->chatSessionRepository, $this->memoryExtractor);
                        break;

                    case Action::SAVE_SETTINGS:
                    case Action::CLEAR_ALL:
                        $controller = new AISettingsController($this->db, $this->chatSessionRepository, $this->envEditor);
                        break;

                    case Action::DELETE_QUERY:
                        $controller = new CacheController($this->status);
                        break;

                    case Action::DELETE_FILES:
                        $controller = new FileController();
                        $controller->setDatabase($this->db);
                        break;

                    case Action::ADD_EMAIL_ACCOUNT:
                    case Action::DELETE_EMAIL_ACCOUNT:
                    case Action::SEND_REPLY:
                        $controller = new EmailController($this->db);
                        break;

                    default:
                        $controller = new ChatController($this->db, $this->chatSessionRepository, $this->agentManager, $this->memoryExtractor, $this->status);
                        break;
                }
            } else {
                $controller = new ChatController($this->db, $this->chatSessionRepository, $this->agentManager, $this->memoryExtractor, $this->status);
            }
        } else {
            if ($apiAction === ApiAction::GET_CACHE) {
                $controller = new CacheController($this->status);
            } elseif ($apiAction === ApiAction::SYNC_LMSTUDIO_LIMIT) {
                $controller = new AISettingsController($this->db, $this->chatSessionRepository, $this->envEditor);
            } elseif ($apiAction === ApiAction::GET_EMAILS || $apiAction === ApiAction::GET_EMAIL_BODY) {
                $controller = new EmailController($this->db);
            } else {
                $controller = new ChatController($this->db, $this->chatSessionRepository, $this->agentManager, $this->memoryExtractor, $this->status);
            }
        }

        $controller->handle();
    }
}
