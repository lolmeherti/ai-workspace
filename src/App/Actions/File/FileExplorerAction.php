<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileExplorerAction extends BaseAction
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
}
