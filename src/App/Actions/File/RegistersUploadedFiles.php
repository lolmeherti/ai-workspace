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
                $systemInstruction = "Generate a natural, clear title for this image file that would make it easy to find later if searched for. Do not include markdown, explanations, or introductory text.";

                $messages = [
                    ['role' => 'system', 'content' => $systemInstruction],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'What is in this image? Provide a clear title/description.'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                        ]
                    ]
                ];
                try {
                    $generatedTitle = trim($agent->chat($messages, false, null, 0.3));
                } catch (\Throwable $e) {
                    $generatedTitle = 'Image: ' . basename($originalName);
                }
            } else {
                if ($extractedText !== null && trim($extractedText) !== '') {
                    $snippet = mb_substr($extractedText, 0, 1000);
                    $systemInstruction = "If you were to name this file based on this snippet, what would the most natural, clear title be? Generate a single descriptive sentence summarizing exactly what this document is (e.g., 'this document is a resume of x profession with x years of experience'). Do not include markdown, explanations, or introductory text.";

                    $messages = [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $snippet]
                    ];
                    try {
                        $generatedTitle = trim($agent->chat($messages, false, null, 0.3));
                    } catch (\Throwable $e) {
                        $generatedTitle = 'Document: ' . basename($originalName);
                    }
                } else {
                    $generatedTitle = 'Document: ' . basename($originalName);
                }
            }
        } else {
            $generatedTitle = ($fileType === 'image' ? 'Image: ' : 'Document: ') . basename($originalName);
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
