<?php

namespace App\Actions\File;

use App\Actions\BaseAction;
use App\Config;
use App\Services\ModelLock;

class FileSyncAction extends BaseAction
{
    use RegistersUploadedFiles;

    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        @set_time_limit(0);

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../../../uploads';
        }
        $uploadDir = rtrim($uploadDir, '/') . '/';

        if (!is_dir($uploadDir)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Upload directory does not exist on disk.'], 500);
            return;
        }

        $physicalPaths = glob($uploadDir . '*');

        try {
            $dbFiles = $this->db->query("SELECT id, physical_name, search_index_version FROM uploaded_files");
            $tracked = [];
            foreach ($dbFiles as $row) {
                $tracked[$row['physical_name']] = $row;
            }
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to read tracked files database: ' . $e->getMessage()], 500);
            return;
        }

        $files = [];
        foreach ($physicalPaths as $filePath) {
            if (is_dir($filePath)) {
                continue;
            }
            $filename = basename($filePath);
            if (str_starts_with($filename, '.') || str_ends_with($filename, '.txt')) {
                continue;
            }
            $files[] = ['path' => $filePath, 'name' => $filename];
        }
        $total = count($files);

        $lockToken = ModelLock::acquireOrBusy(ModelLock::PROCESS_TTL_MS);

        $syncedCount = 0;
        $reindexedCount = 0;
        $failedCount = 0;
        $processed = 0;
        $errors = [];

        try {
            foreach ($files as $file) {
                $filePath = $file['path'];
                $filename = $file['name'];

                if (!isset($tracked[$filename])) {
                    $success = $this->processAndRegisterFile($filePath, $filename, $filename);
                    if ($success) {
                        $syncedCount++;
                    } else {
                        $failedCount++;
                        $errors[] = "Could not sync file: " . $filename;
                    }
                } elseif ((int)$tracked[$filename]['search_index_version'] < Config::CURRENT_INDEX_VERSION) {
                    if ($this->reindexExisting((int)$tracked[$filename]['id'], $filePath, $filename)) {
                        $reindexedCount++;
                    } else {
                        $failedCount++;
                        $errors[] = "Could not re-index file: " . $filename;
                    }
                }

                $processed++;
                ModelLock::setStatus($lockToken, "Indexing files: {$processed} / {$total}");

                if (!ModelLock::renew($lockToken, ModelLock::PROCESS_TTL_MS)) {
                    \App\Logger::logEvent('reindex_aborted', 'Model lock lost during indexing pass', ['processed' => $processed, 'total' => $total], 'warn', 'FileSyncAction');
                    break;
                }
            }
        } finally {
            ModelLock::clearStatus($lockToken);
            ModelLock::release($lockToken);
        }

        $remainingStale = 0;
        try {
            $staleRows = $this->db->query(
                "SELECT COUNT(*) AS c FROM uploaded_files WHERE search_index_version < " . Config::CURRENT_INDEX_VERSION
            );
            $remainingStale = (int)($staleRows[0]['c'] ?? 0);
        } catch (\Exception $e) {
        }

        $this->jsonResponse([
            'status' => 'success',
            'synced_count' => $syncedCount,
            'reindexed_count' => $reindexedCount,
            'failed_count' => $failedCount,
            'remaining_stale_count' => $remainingStale,
            'errors' => $errors,
        ]);
    }

    private function reindexExisting(int $id, string $filePath, string $filename): bool
    {
        $mimeType = @mime_content_type($filePath) ?: 'application/octet-stream';

        try {
            $result = $this->getIngestor()->ingest($filePath, $mimeType, $filename);
            $this->db->update('uploaded_files', [
                'generated_title' => $result['title'],
                'searchable_text' => $result['searchable_text'],
                'search_entities' => json_encode($result['search_entities']),
                'search_index_version' => Config::CURRENT_INDEX_VERSION,
            ], ['id' => $id]);
            return true;
        } catch (\Throwable $e) {
            \App\Logger::logEvent('reindex_failed', 'Re-index failed for ' . $filename . ': ' . $e->getMessage(), ['id' => $id], 'warn', 'FileSyncAction');
            return false;
        }
    }
}
