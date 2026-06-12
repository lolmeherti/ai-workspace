<?php

namespace App\Controllers;

use App\Enums\ApiAction;
use App\Enums\Action;
use App\Actions\File\FileSyncAction;
use App\Actions\File\FileDraftOpenAction;
use App\Actions\File\FileDraftUpdateAction;
use App\Actions\File\FileDraftSaveAction;
use App\Actions\File\FileDraftDiscardAction;
use App\Actions\File\FileDraftDeleteBlocksAction;
use App\Actions\File\FileUploadAction;
use App\Actions\File\FileSearchAction;
use App\Actions\File\FileDeleteAction;
use App\Actions\File\FileExplorerAction;
use App\Actions\File\FileContentAction;

class FileController extends BaseController
{
    private $db = null;

    public function setDatabase($db): void
    {
        $this->db = $db;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $apiActionStr = $_GET['api_action'] ?? '';
            $apiAction = ApiAction::tryFrom($apiActionStr);

            if ($apiAction === ApiAction::UPDATE_DRAFT) {
                (new FileDraftUpdateAction($this->db))->execute();
                return;
            }
            if ($apiAction === ApiAction::SAVE_DRAFT) {
                (new FileDraftSaveAction($this->db))->execute();
                return;
            }
            if ($apiAction === ApiAction::DISCARD_DRAFT) {
                (new FileDraftDiscardAction($this->db))->execute();
                return;
            }
            if ($apiAction === ApiAction::DELETE_DRAFT_BLOCKS) {
                (new FileDraftDeleteBlocksAction($this->db))->execute();
                return;
            }
            if ($apiActionStr === ApiAction::UPLOAD_FILE->value) {
                (new FileUploadAction($this->db))->execute();
                return;
            }

            $this->handlePostRequest();
            return;
        }

        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);

        if ($apiAction === ApiAction::SHOW_IN_EXPLORER) {
            (new FileExplorerAction($this->db))->execute();
        } elseif ($apiAction === ApiAction::GET_FILE_CONTENT) {
            (new FileContentAction($this->db))->execute();
        } elseif ($apiAction === ApiAction::SEARCH_FILES) {
            (new FileSearchAction($this->db))->execute();
        } elseif ($apiAction === ApiAction::OPEN_DRAFT) {
            (new FileDraftOpenAction($this->db))->execute();
        } elseif ($apiActionVal === ApiAction::SYNC_FILES->value) {
            (new FileSyncAction($this->db))->execute();
        }
    }

    private function handlePostRequest(): void
    {
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

        if ($postAction === Action::DELETE_FILES) {
            (new FileDeleteAction($this->db))->execute();
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid post action.'], 400);
        }
    }
}
