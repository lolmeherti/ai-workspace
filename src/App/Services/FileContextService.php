<?php

namespace App\Services;

use App\Database;

/**
 * Resolves an uploaded file's readable content for injection as managed
 * Context Data. Documents inject the raw ORIGINAL text (the .txt sidecar,
 * never the English searchable_text); images inject the stored OCR/description
 * text (searchable_text). Translation is a retrieval-only concern.
 */
final class FileContextService
{
    public function __construct(private Database $db, private string $uploadDir) {}

    /**
     * @return array{physical_name:string, title:string, file_type:string, content:string}|null
     */
    public function lookupContent(string $physicalName): ?array
    {
        $rows = $this->db->query(
            "SELECT physical_name, generated_title, original_name, file_type, searchable_text
             FROM uploaded_files WHERE physical_name = ?",
            [$physicalName]
        );
        if (!$rows) {
            return null;
        }
        $r = $rows[0];

        if ($r['file_type'] === 'image') {
            // Images have no .txt sidecar; the OCR/description text is the content.
            $content = trim((string)($r['searchable_text'] ?? ''));
        } else {
            // Documents: raw ORIGINAL text lives in the .txt sidecar (never indexed).
            $sidecar = rtrim($this->uploadDir, '/\\') . '/' . $r['physical_name'] . '.txt';
            $content = is_file($sidecar) ? trim((string)@file_get_contents($sidecar)) : '';
        }

        if ($content === '') {
            return null;
        }

        return [
            'physical_name' => $r['physical_name'],
            'title'         => trim((string)($r['original_name'] ?? $r['generated_title'] ?? $r['physical_name'])),
            'file_type'     => $r['file_type'],
            'content'       => $content,
        ];
    }
}
