<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDraftDiscardAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file parameter.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');

        if ($draftPath && file_exists($draftPath)) {
            if (unlink($draftPath)) {
                $this->jsonResponse(['status' => 'success']);
            } else {
                $this->jsonResponse(['status' => 'error', 'message' => 'Failed to delete draft file from disk.']);
            }
        } else {
            $this->jsonResponse(['status' => 'success', 'message' => 'No active draft found to discard.']);
        }
    }
}
