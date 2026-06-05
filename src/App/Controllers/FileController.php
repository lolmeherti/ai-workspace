<?php

namespace App\Controllers;

use App\Enums\ApiAction;

class FileController extends BaseController
{
    private $db = null;

    public function setDatabase($db): void
    {
        $this->db = $db;
    }

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
        } elseif ($apiAction === ApiAction::SEARCH_FILES) {
            $this->handleSearchFilesRequest();
        }
    }

    private function handleSearchFilesRequest(): void
    {
        if (ob_get_length()) ob_clean();
        
        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        $toolQuery = $_GET['query'] ?? '';
        $keywords = array_filter(explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $toolQuery)));
        
        $sql = "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at FROM uploaded_files";
        $conditions = [];
        $params = [];
        
        if (!empty($keywords)) {
            $idx = 0;
            foreach ($keywords as $word) {
                if (mb_strlen($word) < 2) continue;
                $conditions[] = "(generated_title LIKE :w{$idx} OR original_name LIKE :o{$idx})";
                $params[":w{$idx}"] = "%{$word}%";
                $params[":o{$idx}"] = "%{$word}%";
                $idx++;
            }
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" OR ", $conditions);
        }
        
        $sql .= " ORDER BY uploaded_at DESC LIMIT 5";
        
        try {
            $matchingFiles = $this->db->query($sql, $params);
            $this->jsonResponse([
                'status' => 'success',
                'query' => $toolQuery,
                'files' => $matchingFiles
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
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