<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\CvRepository;

class CvUploadAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        if (!$this->db) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No database connection available.'], 400);
            return;
        }

        if (!isset($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No CV file was uploaded or an upload error occurred.'], 400);
            return;
        }

        $file = $_FILES['cv'];
        $uploadDir = $this->uploadsDir();

        $filename = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($file['name']));
        $dest = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to store the uploaded CV.'], 500);
            return;
        }

        $hash = hash_file('sha256', $dest);

        $designation = trim($_POST['designation'] ?? '');
        if ($designation === '') {
            $designation = basename($file['name']);
        }

        $repo = new CvRepository($this->db);
        $uuid = $repo->create($designation, $filename, $hash);

        $this->jsonResponse(['status' => 'success', 'cv' => $repo->getByUuid($uuid)]);
    }

    private function uploadsDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../uploads/');
        if ($dir === false) {
            $dir = __DIR__ . '/../../../uploads';
        }
        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}
