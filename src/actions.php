<?php

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Controllers\ChatController;
use App\Controllers\MemoryController;
use App\Controllers\AISettingsController;
use App\Controllers\FileController;
use App\Controllers\CacheController;
use App\Enums\Action;
use App\Enums\ApiAction;

$memoryRepository = new MemoryRepository($db);
$chatSessionRepository = new ChatSessionRepository($db);

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

if ($method === 'POST') {
    if ($postAction !== null) {
        switch ($postAction) {
            case Action::MANUAL_CONSOLIDATE:
            case Action::ADD_MEMORY:
            case Action::DELETE_MEMORY:
            case Action::DELETE_MULTIPLE_MEMORIES:
            case Action::UPDATE_MEMORY:
                $controller = new MemoryController($db, $memoryRepository, $chatSessionRepository, $memoryExtractor);
                break;

            case Action::SAVE_SETTINGS:
            case Action::CLEAR_ALL:
                $controller = new AISettingsController($db, $chatSessionRepository, $envEditor);
                break;

            case Action::DELETE_QUERY:
                $controller = new CacheController($status);
                break;

            case Action::DELETE_FILES:
                $controller = new FileController();
                $controller->setDatabase($db);
                break;

            default:
                $controller = new ChatController($db, $chatSessionRepository, $agentManager, $memoryExtractor, $status);
                break;
        }
    } else {
        $controller = new ChatController($db, $chatSessionRepository, $agentManager, $memoryExtractor, $status);
    }
} else {
    if ($apiAction === ApiAction::SHOW_IN_EXPLORER || $apiAction === ApiAction::GET_FILE_CONTENT || $apiAction === ApiAction::SEARCH_FILES) {
        $controller = new FileController();
        $controller->setDatabase($db);
    } elseif ($apiAction === ApiAction::GET_CACHE) {
        $controller = new CacheController($status);
    } elseif ($apiAction === ApiAction::SYNC_LMSTUDIO_LIMIT) {
        $controller = new AISettingsController($db, $chatSessionRepository, $envEditor);
    } else {
        $controller = new ChatController($db, $chatSessionRepository, $agentManager, $memoryExtractor, $status);
    }
}

$controller->handle();