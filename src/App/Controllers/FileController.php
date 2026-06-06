<?php

namespace App\Controllers;

use App\Enums\ApiAction;
use App\Enums\Action;

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
            $this->handlePostRequest();
            return;
        }

        // Fallback to existing GET action logic
        $apiAction = ApiAction::tryFrom($_GET['api_action'] ?? '');

        if ($apiAction === ApiAction::SHOW_IN_EXPLORER) {
            $this->handleExplorerRequest();
        } elseif ($apiAction === ApiAction::GET_FILE_CONTENT) {
            $this->handleFileContentRequest();
        } elseif ($apiAction === ApiAction::SEARCH_FILES) {
            $this->handleSearchFilesRequest();
        }
    }

    /**
     * Handles secure POST requests such as file deletions.
     */
    private function handlePostRequest(): void
    {
        // Resolve the Action enum standard, matching your existing actions.php routing logic
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
            $this->handleDeleteFilesRequest();
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid post action.'], 400);
        }
    }

    /**
     * Handles paginated searches for the gallery (source=gallery)
     * as well as standard tool quick-searches (fallback).
     */
    private function handleSearchFilesRequest(): void
    {
        if (ob_get_length()) ob_clean();
        
        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        $source = $_GET['source'] ?? '';
        $isGallery = ($source === 'gallery');

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
        
        $sql .= " ORDER BY uploaded_at DESC";

        if ($isGallery) {
            // Support Pagination for the Gallery Interface
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 12)));
            $offset = ($page - 1) * $limit;

            // Safely calculate totals
            $countSql = "SELECT COUNT(*) as total FROM uploaded_files";
            if (!empty($conditions)) {
                $countSql .= " WHERE " . implode(" OR ", $conditions);
            }

            try {
                $countResult = $this->db->query($countSql, $params);
                $total = (int)($countResult[0]['total'] ?? 0);

                // Execute query with limit/offset safely parameter-bound as integers
                $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

                $matchingFiles = $this->db->query($sql, $params);

                // Fetch snippets for document types on-the-fly (keeps DB lightweight)
                foreach ($matchingFiles as &$file) {
                    $file['snippet'] = $this->getFileSnippet($file['physical_name'], $file['file_type']);
                }

                $this->jsonResponse([
                    'status' => 'success',
                    'query' => $toolQuery,
                    'files' => $matchingFiles,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'pages' => ceil($total / $limit)
                    ]
                ]);
            } catch (\Exception $e) {
                $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
            }
        } else {
            // Default backward-compatible quick search (LIMIT 5) for assistant tools
            $sql .= " LIMIT 5";
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
    }

    /**
     * Deletes single or multiple files from physical disk and the database.
     */
    private function handleDeleteFilesRequest(): void
    {
        if (ob_get_length()) ob_clean();

        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        // Support both single file deletion ('id') and multi-selection deletion ('ids' array)
        $ids = [];
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
        } elseif (isset($_POST['id'])) {
            $ids[] = (int)$_POST['id'];
        }

        $ids = array_filter($ids);

        if (empty($ids)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No valid file IDs provided.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $deletedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                // Fetch details using your safe database selector
                $files = $this->db->selectSafe('uploaded_files', ['id' => $id]);
                if (empty($files)) {
                    continue; // File already deleted or missing
                }
                
                $file = $files[0];
                $physicalName = $file['physical_name'];

                $filePath = realpath($uploadDir . '/' . $physicalName);
                $txtPath = realpath($uploadDir . '/' . $physicalName . '.txt');

                // 1. Delete the primary physical file if it exists
                if ($filePath && str_starts_with($filePath, $uploadDir) && file_exists($filePath)) {
                    unlink($filePath);
                }

                // 2. Delete the associated companion text extraction file if it exists
                if ($txtPath && str_starts_with($txtPath, $uploadDir) && file_exists($txtPath)) {
                    unlink($txtPath);
                }

                // 3. Delete from the database
                $this->db->query("DELETE FROM uploaded_files WHERE id = :id", [':id' => $id]);

                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "ID {$id}: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $this->jsonResponse([
                'status' => 'partial_success',
                'message' => 'Some files failed to delete.',
                'deleted_count' => $deletedCount,
                'errors' => $errors
            ], 207);
        } else {
            $this->jsonResponse([
                'status' => 'success',
                'message' => 'Successfully deleted ' . $deletedCount . ' file(s).'
            ]);
        }
    }

    /**
     * Grabs a brief 150-character snippet of the document to serve as a preview.
     */
    private function getFileSnippet(string $physicalName, string $fileType): string
    {
        $lowerType = strtolower($fileType);
        if (str_starts_with($lowerType, 'image/') || $lowerType === 'image') {
            return '';
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $txtPath = $uploadDir . '/' . $physicalName . '.txt';
        $origPath = $uploadDir . '/' . $physicalName;

        $targetPath = null;
        if (file_exists($txtPath)) {
            $targetPath = $txtPath;
        } elseif (file_exists($origPath) && (str_ends_with(strtolower($physicalName), '.txt') || str_starts_with(strtolower($fileType), 'text/'))) {
            $targetPath = $origPath;
        }

        if ($targetPath) {
            $handle = @fopen($targetPath, 'r');
            if ($handle) {
                $snippet = fread($handle, 150);
                fclose($handle);
                
                $snippet = mb_convert_encoding($snippet, 'UTF-8', 'UTF-8');
                return preg_replace('/\s+/', ' ', trim($snippet));
            }
        }
        return '';
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