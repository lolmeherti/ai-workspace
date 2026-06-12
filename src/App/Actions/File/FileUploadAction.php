<?php

namespace App\Actions\File;

use App\Actions\BaseAction;

class FileUploadAction extends BaseAction
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
}
