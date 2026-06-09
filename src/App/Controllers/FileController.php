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
            $apiActionStr = $_GET['api_action'] ?? '';
            $apiAction = ApiAction::tryFrom($apiActionStr);

            if ($apiAction === ApiAction::UPDATE_DRAFT) {
                $this->handleUpdateDraftRequest();
                return;
            } elseif ($apiAction === ApiAction::SAVE_DRAFT) {
                $this->handleSaveDraftRequest();
                return;
            } elseif ($apiAction === ApiAction::DISCARD_DRAFT) {
                $this->handleDiscardDraftRequest();
                return;
            } elseif ($apiAction === ApiAction::DELETE_DRAFT_BLOCKS) {
                $this->handleDeleteDraftBlocksRequest();
                return;
            }

            if ($apiActionStr === 'upload_file') {
                $this->handleDirectUploadRequest();
                return;
            }

            $this->handlePostRequest();
            return;
        }

        $apiActionVal = $_GET['api_action'] ?? '';
        $apiAction = ApiAction::tryFrom($apiActionVal);

        if ($apiAction === ApiAction::SHOW_IN_EXPLORER) {
            $this->handleExplorerRequest();
        } elseif ($apiAction === ApiAction::GET_FILE_CONTENT) {
            $this->handleFileContentRequest();
        } elseif ($apiAction === ApiAction::SEARCH_FILES) {
            $this->handleSearchFilesRequest();
        } elseif ($apiAction === ApiAction::OPEN_DRAFT) {
            $this->handleOpenDraftRequest();
        } elseif ($apiActionVal === 'sync_files') {
            $this->handleSyncFilesRequest();
        }
    }

    /**
     * Handles secure POST requests such as file deletions.
     */
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
            $this->handleDeleteFilesRequest();
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid post action.'], 400);
        }
    }

    /**
     * Scans physical uploads folder on disk, finding and AI-indexing untracked files.
     */
    private function handleSyncFilesRequest(): void
    {
        if (ob_get_length()) ob_clean();

        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../../uploads';
        }
        $uploadDir = rtrim($uploadDir, '/') . '/';

        if (!is_dir($uploadDir)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Upload directory does not exist on disk.'], 500);
            return;
        }

        // 1. Scan physical files
        $physicalPaths = glob($uploadDir . '*');
        
        // 2. Query already tracked file records in the DB
        try {
            $dbFiles = $this->db->query("SELECT physical_name FROM uploaded_files");
            $trackedNames = array_column($dbFiles, 'physical_name');
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to read tracked files database: ' . $e->getMessage()], 500);
            return;
        }

        $syncedCount = 0;
        $errors = [];

        foreach ($physicalPaths as $filePath) {
            if (is_dir($filePath)) {
                continue;
            }

            $filename = basename($filePath);

            // Skip helper files, hidden operating system files, and companion text extractions
            if (str_starts_with($filename, '.') || str_ends_with($filename, '.txt')) {
                continue;
            }

            // Sync if physical file is not yet recorded in DB
            if (!in_array($filename, $trackedNames)) {
                $success = $this->processAndRegisterFile($filePath, $filename, $filename);
                if ($success) {
                    $syncedCount++;
                } else {
                    $errors[] = "Could not sync file: " . $filename;
                }
            }
        }

        $this->jsonResponse([
            'status' => 'success',
            'synced_count' => $syncedCount,
            'errors' => $errors
        ]);
    }

    /**
     * Opens a file, clones its clean text content to a .draft version, 
     * and parses it on-the-fly into indexed blocks.
     */
    private function handleOpenDraftRequest(): void
    {
        if (ob_get_length()) ob_clean();

        $file = $_GET['file'] ?? '';
        
        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file name.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        $origPath = realpath($uploadDir . '/' . $file);

        // Resolve which file holds the text content we want to edit
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

        // Define draft path
        $draftPath = $uploadDir . '/' . $file . '.draft.txt';

        // Copy original source text to draft on disk if it doesn't exist yet
        if (!file_exists($draftPath)) {
            if (!copy($sourcePath, $draftPath)) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Failed to initialize draft file on disk.'], 500);
                return;
            }
        }

        // Read draft content and split strictly on single newlines
        $content = file_get_contents($draftPath);
        $content = str_replace("\r\n", "\n", $content); // Normalize line endings
        $lines = explode("\n", $content);

        $blocks = [];
        foreach ($lines as $index => $line) {
            $blocks[] = [
                'id' => 'b-' . ($index + 1),
                'content' => rtrim($line) // Trim trailing carriage returns/spaces
            ];
        }

        $this->jsonResponse([
            'status' => 'success',
            'file' => $file,
            'blocks' => $blocks
        ]);
    }

    /**
     * Updates a specific block ID (line) within the draft on disk and returns the updated block map.
     */
    private function handleUpdateDraftRequest(): void
    {
        if (ob_get_length()) ob_clean();

        // Read POST parameters from raw input stream
        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';
        $blockId = $input['block_id'] ?? '';
        $newContent = $input['content'] ?? '';
        $replaceRange = $input['replace_range'] ?? []; // Array of block IDs to delete on merge

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file) || empty($blockId)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid update parameters.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');

        if (!$draftPath || !file_exists($draftPath)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No active draft found.'], 404);
            return;
        }

        // Parse blocks strictly on single newlines
        $content = file_get_contents($draftPath);
        $content = str_replace("\r\n", "\n", $content);
        $lines = explode("\n", $content);

        // Extract target block index (e.g., 'b-3' => index 2)
        $index = (int)str_replace('b-', '', $blockId) - 1;

        // Apply the updated content to the primary target block
        if ($index >= 0 && $index < count($lines)) {
            $lines[$index] = $newContent;
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Target block index out of bounds.'], 400);
            return;
        }

        // REMOVE MERGED BLOCKS: Unset any other indices in the replacement range
        if (is_array($replaceRange) && !empty($replaceRange)) {
            foreach ($replaceRange as $idToRemove) {
                $remIdx = (int)str_replace('b-', '', $idToRemove) - 1;
                if ($remIdx >= 0 && $remIdx < count($lines)) {
                    unset($lines[$remIdx]);
                }
            }
        }

        // Re-index array keys to prevent holes in the document layout
        $lines = array_values($lines);

        // Reconstruct document and write to disk
        $updatedContent = implode("\n", $lines);
        file_put_contents($draftPath, $updatedContent);

        // Return the fresh block array to synchronize frontend state
        $blocks = [];
        foreach ($lines as $idx => $line) {
            $blocks[] = [
                'id' => 'b-' . ($idx + 1),
                'content' => rtrim($line)
            ];
        }

        $this->jsonResponse([
            'status' => 'success',
            'blocks' => $blocks
        ]);
    }

    /**
     * Commits the draft changes to the main file or companion text extraction, 
     * then cleans up the draft asset.
     */
    private function handleSaveDraftRequest(): void
    {
        if (ob_get_length()) ob_clean();

        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file parameter.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        $origPath = realpath($uploadDir . '/' . $file);

        if (!$draftPath || !file_exists($draftPath)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Active draft not found.'], 404);
            return;
        }

        // Determine target file to overwrite
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

        // Overwrite target and clean up draft
        if (copy($draftPath, $targetPath)) {
            unlink($draftPath);
            $this->jsonResponse(['status' => 'success']);
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to commit draft changes.'], 500);
        }
    }


    /**
     * Deletes the sandbox draft file from the disk to preserve directory cleanliness.
     */
    private function handleDiscardDraftRequest(): void
    {
        if (ob_get_length()) ob_clean();

        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid file parameter.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
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

    /**
     * Safely unlinks a single or multiple block IDs from the draft file on disk, 
     * automatically re-indexing remaining lines.
     */
    private function handleDeleteDraftBlocksRequest(): void
    {
        if (ob_get_length()) ob_clean();

        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? '';
        $blockIds = $input['block_ids'] ?? []; // Array of block IDs to delete

        if (empty($file) || !preg_match('/^[a-zA-Z0-9._\-]+$/', $file) || empty($blockIds)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid parameters.'], 400);
            return;
        }

        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');

        if (!$draftPath || !file_exists($draftPath)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No active draft found.'], 404);
            return;
        }

        // Read draft content
        $content = file_get_contents($draftPath);
        $content = str_replace("\r\n", "\n", $content);
        $lines = explode("\n", $content);

        // Map block IDs (e.g., 'b-3') to zero-indexed array keys
        $indicesToRemove = [];
        foreach ($blockIds as $id) {
            $indicesToRemove[] = (int)str_replace('b-', '', $id) - 1;
        }

        // Filter out target lines
        foreach ($indicesToRemove as $remIdx) {
            if (isset($lines[$remIdx])) {
                unset($lines[$remIdx]);
            }
        }

        // Re-index remaining keys to prevent holes in document structure
        $lines = array_values($lines);

        // Write back to disk
        $updatedContent = implode("\n", $lines);
        file_put_contents($draftPath, $updatedContent);

        // Parse and return newly re-split block elements
        $blocks = [];
        foreach ($lines as $idx => $line) {
            $blocks[] = [
                'id' => 'b-' . ($idx + 1),
                'content' => rtrim($line)
            ];
        }

        $this->jsonResponse([
            'status' => 'success',
            'blocks' => $blocks
        ]);
    }
    

    /**
     * Handles web UI dropped or pasted uploads (multipart form data).
     */
    private function handleDirectUploadRequest(): void
    {
        if (ob_get_length()) ob_clean();

        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No file was uploaded or an upload error occurred.'], 400);
            return;
        }

        $uploadedFile = $_FILES['file'];
        $fileData = $this->processAndRegisterFile($uploadedFile['tmp_name'], $uploadedFile['name']);

        if ($fileData) {
            $this->jsonResponse([
                'status' => 'success',
                'message' => 'File uploaded and indexed successfully.',
                'file' => $fileData
            ]);
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'An error occurred while indexing your file.'], 500);
        }
    }

/**
     * Reusable helper to process physical files, run extraction, generate AI titles, and save them.
     * Returns the array of record details on success, or null on failure.
     */
    private function processAndRegisterFile(string $sourcePath, string $originalName, ?string $targetFilename = null): ?array
    {
        $uploadDir = realpath(__DIR__ . '/../../uploads/');
        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../../uploads';
        }
        $uploadDir = rtrim($uploadDir, '/') . '/';

        if ($targetFilename === null) {
            $timestamp = time();
            $filename = $timestamp . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($originalName));
            $dest = $uploadDir . $filename;
            
            if (!move_uploaded_file($sourcePath, $dest)) {
                return null;
            }
        } else {
            $filename = $targetFilename;
            $dest = $uploadDir . $filename;
        }

        $mimeType = @mime_content_type($dest) ?: 'application/octet-stream';
        $fileType = str_starts_with($mimeType, 'image/') ? 'image' : 'document';
        $extractedText = null;

        if ($fileType !== 'image') {
            try {
                if (class_exists('\App\FileExtractor')) {
                    $extractedText = \App\FileExtractor::extractText($dest, $originalName);
                }
            } catch (\Throwable $e) {
                $extractedText = '[System Error parsing document: ' . $e->getMessage() . ']';
            }

            if ($extractedText !== null && trim($extractedText) !== '') {
                if (mb_strlen($extractedText) > 40000) {
                    $extractedText = mb_substr($extractedText, 0, 40000) . "\n\n... [Content truncated due to length limits]";
                }
                file_put_contents($dest . '.txt', $extractedText);
            } else {
                file_put_contents($dest . '.txt', '[Could not extract text content]');
            }
        }

        // Generate clean AI title/summarization
        $generatedTitle = 'Untitled File';
        $agent = class_exists('\App\AgentManager') ? new \App\AgentManager() : null;

        if ($agent) {
            if ($fileType === 'image') {
                $base64 = base64_encode(file_get_contents($dest));
                $systemInstruction = "Generate a natural, clear title for this image file that would make it easy to find later if searched for. Do not include markdown, explanations, or introductory text.";

                $messages = [
                    ['role' => 'system', 'content' => $systemInstruction],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'What is in this image? Provide a clear title/description.'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                        ]
                    ]
                ];
                try {
                    $generatedTitle = trim($agent->chat($messages, false, null, 0.3));
                } catch (\Throwable $e) {
                    $generatedTitle = 'Image: ' . basename($originalName);
                }
            } else {
                if ($extractedText !== null && trim($extractedText) !== '') {
                    $snippet = mb_substr($extractedText, 0, 1000);
                    $systemInstruction = "If you were to name this file based on this snippet, what would the most natural, clear title be? Generate a single descriptive sentence summarizing exactly what this document is (e.g., 'this document is a resume of x profession with x years of experience'). Do not include markdown, explanations, or introductory text.";

                    $messages = [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $snippet]
                    ];
                    try {
                        $generatedTitle = trim($agent->chat($messages, false, null, 0.3));
                    } catch (\Throwable $e) {
                        $generatedTitle = 'Document: ' . basename($originalName);
                    }
                } else {
                    $generatedTitle = 'Document: ' . basename($originalName);
                }
            }
        } else {
            $generatedTitle = ($fileType === 'image' ? 'Image: ' : 'Document: ') . basename($originalName);
        }

        $fileData = [
            'original_name' => basename($originalName),
            'physical_name' => $filename,
            'generated_title' => $generatedTitle,
            'file_type' => $fileType
        ];

        // Insert database record
        $success = $this->db->insert('uploaded_files', [
            'session_id' => null,
            'original_name' => $fileData['original_name'],
            'physical_name' => $fileData['physical_name'],
            'generated_title' => $fileData['generated_title'],
            'file_type' => $fileData['file_type']
        ]);

        return $success ? $fileData : null;
    }

    /**
     * Handles paginated searches for the gallery (source=gallery)
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
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 12)));
            $offset = ($page - 1) * $limit;

            $countSql = "SELECT COUNT(*) as total FROM uploaded_files";
            if (!empty($conditions)) {
                $countSql .= " WHERE " . implode(" OR ", $conditions);
            }

            try {
                $countResult = $this->db->query($countSql, $params);
                $total = (int)($countResult[0]['total'] ?? 0);

                $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $matchingFiles = $this->db->query($sql, $params);

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
                $files = $this->db->selectSafe('uploaded_files', ['id' => $id]);
                if (empty($files)) {
                    continue;
                }
                
                $file = $files[0];
                $physicalName = $file['physical_name'];

                $filePath = realpath($uploadDir . '/' . $physicalName);
                $txtPath = realpath($uploadDir . '/' . $physicalName . '.txt');

                if ($filePath && str_starts_with($filePath, $uploadDir) && file_exists($filePath)) {
                    unlink($filePath);
                }

                if ($txtPath && str_starts_with($txtPath, $uploadDir) && file_exists($txtPath)) {
                    unlink($txtPath);
                }

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
     * Grabs a brief snippet of the document to serve as a preview.
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
        
        // 1. ALWAYS PREFER THE SANDBOX DRAFT FIRST (For real-time sync)
        $draftPath = realpath($uploadDir . '/' . $file . '.draft.txt');
        if ($draftPath && str_starts_with($draftPath, $uploadDir) && file_exists($draftPath)) {
            $content = file_get_contents($draftPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }
        
        // 2. FALLBACK A: Companion text extraction (.txt)
        $txtPath = realpath($uploadDir . '/' . $file . '.txt');
        if ($txtPath && str_starts_with($txtPath, $uploadDir) && file_exists($txtPath)) {
            $content = file_get_contents($txtPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }
        
        // 3. FALLBACK B: Original file (if text/markdown)
        $origPath = realpath($uploadDir . '/' . $file);
        if ($origPath && str_starts_with($origPath, $uploadDir) && file_exists($origPath)) {
            $content = file_get_contents($origPath);
            $this->jsonResponse(['status' => 'success', 'content' => $content]);
            return;
        }
        
        $this->jsonResponse(['status' => 'error', 'message' => 'Text content not found.'], 404);
    }
}