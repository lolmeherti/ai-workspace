<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileDeleteAction extends BaseAction
{
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

        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
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
}
