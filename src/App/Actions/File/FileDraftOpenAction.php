<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDraftOpenAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $file = $_GET['file'] ?? '';

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file name.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        $origPath = realpath($uploadDir . '/' . $file);

        $sourcePath = null;
        if ($txtPath && file_exists($txtPath)) {
            $sourcePath = $txtPath;
        } elseif ($origPath && file_exists($origPath) && (str_ends_with(strtolower($file), '.txt') || str_ends_with(strtolower($file), '.md'))) {
            $sourcePath = $origPath;
        }

        if (!$sourcePath) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No editable text content found for this file.'], 404);
            return;
        }

        $draftPath = $uploadDir . '/' . $file . '.draft.txt';

        if (!file_exists($draftPath)) {
            if (!copy($sourcePath, $draftPath)) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Failed to initialize draft file on disk.'], 500);
                return;
            }
        }

        $content = file_get_contents($draftPath);
        $content = str_replace("\r\n", "\n", $content);
        $lines = explode("\n", $content);

        $blocks = [];
        foreach ($lines as $index => $line) {
            $blocks[] = [
                'id' => 'b-' . ($index + 1),
                'content' => rtrim($line)
            ];
        }

        $this->jsonResponse([
            'status' => 'success',
            'file' => $file,
            'blocks' => $blocks
        ]);
    }
}
