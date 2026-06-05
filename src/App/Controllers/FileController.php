<?php

namespace App\Controllers;

use App\Enums\ApiAction;

class FileController extends BaseController
{
    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        $apiAction = ApiAction::tryFrom($_GET['api_action'] ?? '');

        if ($apiAction === ApiAction::SHOW_IN_EXPLORER) {
            $this->handleExplorerRequest();
        } elseif ($apiAction === ApiAction::GET_FILE_CONTENT) {
            $this->handleFileContentRequest();
        }
    }

    private function handleExplorerRequest(): void
    {
        $file = $_GET['file'] ?? '';
        
        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file name.'], 400);
            return;
        }
        
        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $fullPath = realpath($uploadDir . '/' . $file);
        
        if ($fullPath && str_starts_with($fullPath, $uploadDir) && file_exists($fullPath)) {
            if (stristr(PHP_OS, 'WIN')) {
                pclose(popen("start /B explorer.exe /select,\"" . $fullPath . "\"", "r"));
                $this->jsonResponse(['status' => 'success']);
            } else {
                $this->jsonResponse([
                    'status' => 'fallback',
                    'url' => 'uploads/' . $file,
                    'physical_path' => 'uploads/' . $file
                ]);
            }
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'File not found on disk.'], 404);
        }
    }

    private function handleFileContentRequest(): void
    {
        $file = $_GET['file'] ?? '';
        
        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file name.'], 400);
            return;
        }
        
        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        
        if ($txtPath && str_starts_with($txtPath, $uploadDir) && file_exists($txtPath)) {
            $content = file_get_contents($txtPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
        } else {
            $origPath = realpath($uploadDir . '/' . $file);
            if ($origPath && str_starts_with($origPath, $uploadDir) && file_exists($origPath)) {
                $content = file_get_contents($origPath);
                $this->jsonResponse(['status' => 'success', 'content' => $content]);
            } else {
                $this->jsonResponse(['status' => 'error', 'message' => 'Extracted text document not found.'], 404);
            }
        }
    }
}