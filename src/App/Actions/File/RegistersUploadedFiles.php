<?php

namespace App\Actions\File;

trait RegistersUploadedFiles
{
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
        $extractedText = null;

        if ($fileType !== 'image') {
            try {
                if (class_exists('\App\FileExtractor')) {
                    $extractedText = \App\FileExtractor::extractText($dest, $originalName);
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
        $agent = class_exists('\App\AgentManager') ? new \App\AgentManager() : null;

        if ($agent) {
            if ($fileType === 'image') {
                $base64 = base64_encode(file_get_contents($dest));
                $systemInstruction = "Give a short descriptive title for what this image shows. Prioritize: document or image type, what it is about, who issued it, and when. Do not describe colors, layout, or visual properties. Output only the title, nothing else.";

                $messages = [
                    ['role' => 'system', 'content' => $systemInstruction],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'What keywords describe this image?'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                        ]
                    ]
                ];
                try {
                    $generatedTitle = $agent->chat($messages, false, null, 0.3);
                } catch (\Throwable $e) {
                    $generatedTitle = 'image, ' . pathinfo($originalName, PATHINFO_FILENAME);
                }
            } else {
                if ($extractedText !== null && trim($extractedText) !== '') {
                    $snippet = mb_substr($extractedText, 0, 1000);
                    $systemInstruction = "Give a short descriptive title for this document. Prioritize: document type, who wrote or issued it, what it is about, and when. Do not list keywords or describe formatting. Output only the title, nothing else.";

                    $messages = [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $snippet]
                    ];
                    try {
                        $generatedTitle = $agent->chat($messages, false, null, 0.3);
                    } catch (\Throwable $e) {
                        $generatedTitle = 'document, ' . pathinfo($originalName, PATHINFO_FILENAME);
                    }
                } else {
                    $generatedTitle = 'document, ' . pathinfo($originalName, PATHINFO_FILENAME);
                }
            }
        } else {
            $generatedTitle = ($fileType === 'image' ? 'image, ' : 'document, ') . pathinfo($originalName, PATHINFO_FILENAME);
        }

        $fileData = [
            'original_name' => basename($originalName),
            'physical_name' => $filename,
            'generated_title' => $generatedTitle,
            'file_type' => $fileType
        ];

        $success = $this->db->insert('uploaded_files', [
            'session_id' => null,
            'original_name' => $fileData['original_name'],
            'physical_name' => $fileData['physical_name'],
            'generated_title' => $fileData['generated_title'],
            'file_type' => $fileData['file_type']
        ]);

        return $success ? $fileData : null;
    }
}
