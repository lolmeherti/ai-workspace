<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Config;

class FileAttachmentService
{
    private Database $db;
    private AgentManager $agent;
    private FileIngestor $ingestor;
    private string $uploadDir;

    public function __construct(Database $db, AgentManager $agent, string $uploadDir)
    {
        $this->db = $db;
        $this->agent = $agent;
        $this->ingestor = new FileIngestor($agent);
        $this->uploadDir = $uploadDir;
    }

    public function handleUpload(int $sessionId, ?array $imageFile): ?string
    {
        if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $timestamp = time();
        $filename = $timestamp . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($imageFile['name']));
        $dest = $this->uploadDir . $filename;

        if (!move_uploaded_file($imageFile['tmp_name'], $dest)) {
            return null;
        }

        $imagePath = 'uploads/' . $filename;
        $mimeType = @mime_content_type($dest) ?: 'application/octet-stream';
        $fileType = str_starts_with($mimeType, 'image/') ? 'image' : 'document';

        $result = $this->ingestor->ingest($dest, $mimeType, $imageFile['name']);

        if ($fileType === 'document') {
            $sidecar = $result['extracted_text'] !== '' ? $result['extracted_text'] : '[Could not extract text content]';
            file_put_contents($dest . '.txt', $sidecar);
        }

        $this->db->insert('uploaded_files', [
            'session_id' => $sessionId,
            'original_name' => basename($imageFile['name']),
            'physical_name' => $filename,
            'generated_title' => $result['title'],
            'searchable_text' => $result['searchable_text'],
            'search_entities' => json_encode($result['search_entities']),
            'search_index_version' => Config::CURRENT_INDEX_VERSION,
            'file_type' => $fileType,
        ]);

        return $imagePath;
    }
}
