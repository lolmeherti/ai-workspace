<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDraftSaveAction extends BaseAction
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
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        $origPath = realpath($uploadDir . '/' . $file);

        if (!$draftPath || !file_exists($draftPath)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Active draft not found.'], 404);
            return;
        }

        $targetPath = null;
        if ($txtPath && file_exists($txtPath)) {
            $targetPath = $txtPath;
        } elseif ($origPath && file_exists($origPath) && (str_ends_with(strtolower($file), '.txt') || str_ends_with(strtolower($file), '.md'))) {
            $targetPath = $origPath;
        }

        if (!$targetPath) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to resolve save target.'], 500);
            return;
        }

        if (copy($draftPath, $targetPath)) {
            unlink($draftPath);
            $this->jsonResponse(['status' => 'success']);
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to commit draft changes.'], 500);
        }
    }
}
