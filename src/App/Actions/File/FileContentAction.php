<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileContentAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $file = $_GET['file'] ?? '';

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file name.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');

        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');
        if ($draftPath && str_starts_with($draftPath, $uploadDir) && file_exists($draftPath)) {
            $content = file_get_contents($draftPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }

        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        if ($txtPath && str_starts_with($txtPath, $uploadDir) && file_exists($txtPath)) {
            $content = file_get_contents($txtPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }

        $origPath = realpath($uploadDir . '/' . $file);
        if ($origPath && str_starts_with($origPath, $uploadDir) && file_exists($origPath)) {
            $content = file_get_contents($origPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }

        $this->jsonResponse(['status' => 'error', 'message' => 'Text content not found.'], 404);
    }
}
