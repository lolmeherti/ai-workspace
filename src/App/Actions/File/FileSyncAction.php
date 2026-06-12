<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

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

            if (str_starts_with($filename, '.') || str_ends_with($filename, '.txt')) {
                continue;
            }

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
}
