<?php

namespace App\Actions\File;

use App\Services\FileIngestor;

trait RegistersUploadedFiles
{
    private ?FileIngestor $ingestor = null;

    private function getIngestor(): FileIngestor
    {
        if ($this->ingestor === null) {
            $this->ingestor = new FileIngestor(new \App\AgentManager());
        }
        return $this->ingestor;
    }

    private function processAndRegisterFile(string $sourcePath, string $originalName, ?string $targetFilename = null): ?array
    {
        $uploadDir = realpath(__DIR__ . '/../../../uploads/');
        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../../../uploads';
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

        $result = $this->getIngestor()->ingest($dest, $mimeType, $originalName);

        if ($fileType === 'document') {
            $sidecar = $result['extracted_text'] !== '' ? $result['extracted_text'] : '[Could not extract text content]';
            file_put_contents($dest . '.txt', $sidecar);
        }

        $fileData = [
            'original_name' => basename($originalName),
            'physical_name' => $filename,
            'generated_title' => $result['title'],
            'file_type' => $fileType,
        ];

        $success = $this->db->insert('uploaded_files', [
            'session_id' => null,
            'original_name' => $fileData['original_name'],
            'physical_name' => $fileData['physical_name'],
            'generated_title' => $fileData['generated_title'],
            'searchable_text' => $result['searchable_text'],
            'search_entities' => json_encode($result['search_entities']),
            'search_index_version' => \App\Config::CURRENT_INDEX_VERSION,
            'file_type' => $fileData['file_type'],
        ]);

        return $success ? $fileData : null;
    }
}
