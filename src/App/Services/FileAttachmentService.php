<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Config;

class FileAttachmentService
{
    private Database $db;
    private AgentManager $agent;
    private string $uploadDir;

    public function __construct(Database $db, AgentManager $agent, string $uploadDir)
    {
        $this->db = $db;
        $this->agent = $agent;
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
        $extractedText = null;

        if ($fileType !== 'image') {
            try {
                if (!class_exists('\App\FileExtractor') && file_exists(__DIR__ . '/../FileExtractor.php')) {
                    require_once __DIR__ . '/../FileExtractor.php';
                }

                if (class_exists('\App\FileExtractor')) {
                    $extractedText = \App\FileExtractor::extractText($dest, $imageFile['name']);
                } else {
                    $extractedText = '[System Error: FileExtractor missing]';
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

        $generatedTitle = 'Untitled File';
        if ($fileType === 'image') {
            $base64 = base64_encode(file_get_contents($dest));
            $systemInstruction = <<<TEXT
Give a short descriptive title for what this image shows. Prioritize: document or image type, what it is about, who issued it, and when. Do not describe colors, layout, or visual properties. Output only the title, nothing else.
TEXT;

            $imageMessages = [
                [
                    'role' => 'system',
                    'content' => $systemInstruction
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'What keywords describe this image?'],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                    ]
                ]
            ];
            try {
                $generatedTitle = $this->agent->chat($imageMessages, false, null, 0.3);
            } catch (\Throwable $e) {
                $generatedTitle = 'image, ' . pathinfo($imageFile['name'], PATHINFO_FILENAME);
            }
        } else {
            if ($extractedText !== null && trim($extractedText) !== '') {
                $snippet = mb_substr($extractedText, 0, 1000);
                $systemInstruction = <<<TEXT
Give a short descriptive title for this document. Prioritize: document type, who wrote or issued it, what it is about, and when. Do not list keywords or describe formatting. Output only the title, nothing else.
TEXT;

                $docMessages = [
                    [
                        'role' => 'system',
                        'content' => $systemInstruction
                    ],
                    [
                        'role' => 'user',
                        'content' => $snippet
                    ]
                ];
                try {
                    $generatedTitle = $this->agent->chat($docMessages, false, null, 0.3);
                } catch (\Throwable $e) {
                    $generatedTitle = 'document, ' . pathinfo($imageFile['name'], PATHINFO_FILENAME);
                }
            } else {
                $generatedTitle = 'document, ' . pathinfo($imageFile['name'], PATHINFO_FILENAME);
            }
        }

        $this->db->insert('uploaded_files', [
            'session_id' => $sessionId,
            'original_name' => basename($imageFile['name']),
            'physical_name' => $filename,
            'generated_title' => $generatedTitle,
            'file_type' => $fileType
        ]);

        return $imagePath;
    }
}