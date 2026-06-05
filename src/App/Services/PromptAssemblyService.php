<?php

namespace App\Services;

use App\Agents\MemorySelector;
use App\Config;

class PromptAssemblyService
{
    private ?MemorySelector $memorySelector;
    private string $uploadDir;

    public function __construct(?MemorySelector $memorySelector, string $uploadDir)
    {
        $this->memorySelector = $memorySelector;
        $this->uploadDir = $uploadDir;
    }

    public function buildSystemPrompt(string $condensedContext, bool $usedCache, string $query): string
    {
        $currentDate = date('F j, Y');
        $cutoffDate = 'early 2024';

        $systemPrompt = <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant. Today's date is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}. You are being supplemented with up to date data from a third party source via content injection. 
This is what keeps you up to date. If you see data from what you perceive to be the future, don't worry about it and understand that its relatively reliable data.

If the user asks for a file, asks to recall a document/image, or asks if you have a file on disk, do not make assumptions. You can search the database of uploaded files by outputting a JSON block with the following exact format as your ONLY output:
{"tool": "search_files", "query": "search words here"}
TEXT;

        if (!empty($condensedContext)) {
            $systemPrompt .= <<<TEXT


LIVE WEB SEARCH CONTEXT:
{$condensedContext}
TEXT;
            if ($usedCache) {
                $systemPrompt .= <<<TEXT

(Note: This context was retrieved from your recent semantic memory cache).
TEXT;
            }
        }

        if ($this->memorySelector) {
            $relevantMemories = $this->memorySelector->selectRelevantMemory($query);
            if (!empty($relevantMemories)) {
                $systemPrompt .= <<<TEXT


Relevant details you recall about the user:
{$relevantMemories}
TEXT;
            }
        }

        return $systemPrompt;
    }

    public function buildMessagesArray(string $systemPrompt, array $history): array
    {
        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];

        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentHistory = array_slice($history, -$rollingLimit);

        foreach ($recentHistory as $row) {
            $hasImage = false;
            $messageContent = $row['message'];

            if (preg_match_all('/\[File:\s*([a-zA-Z0-9._\-]+)\]/', $messageContent, $matches)) {
                foreach ($matches[1] as $idx => $matchedFilename) {
                    $fullFilePath = $this->uploadDir . $matchedFilename;
                    $txtPath = $fullFilePath . '.txt';

                    if (file_exists($txtPath)) {
                        $docText = file_get_contents($txtPath);
                        $messageContent .= "\n\n[Referenced File Content for {$matchedFilename}]:\n{$docText}\n[End of Referenced File Content]";
                    } elseif (file_exists($fullFilePath)) {
                        $mimeType = @mime_content_type($fullFilePath) ?: 'application/octet-stream';
                        if (str_starts_with($mimeType, 'image/')) {
                            $hasImage = true;
                            $base64 = base64_encode(file_get_contents($fullFilePath));
                            $messages[] = [
                                'role' => $row['role'],
                                'content' => [
                                    ['type' => 'text', 'text' => str_replace($matches[0][$idx], '', $messageContent)],
                                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                                ]
                            ];
                        }
                    }
                }
            }

            if (!$hasImage) {
                if (!empty($row['image_path']) && file_exists(__DIR__ . '/../../' . $row['image_path'])) {
                    $fullFilePath = __DIR__ . '/../../' . $row['image_path'];
                    $mimeType = @mime_content_type($fullFilePath) ?: 'application/octet-stream';

                    if (str_starts_with($mimeType, 'image/')) {
                        $hasImage = true;
                        $base64 = base64_encode(file_get_contents($fullFilePath));
                        $messages[] = [
                            'role' => $row['role'],
                            'content' => [
                                ['type' => 'text', 'text' => $messageContent],
                                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]]
                            ]
                        ];
                    } else {
                        $txtPath = $fullFilePath . '.txt';
                        if (file_exists($txtPath)) {
                            $docText = file_get_contents($txtPath);
                            $cleanFileName = preg_replace('/^[a-z0-9]+_/', '', basename($row['image_path']));
                            $messageContent = <<<TEXT
[Attached Document: {$cleanFileName}] {$docText} {$messageContent}
TEXT;
                        }
                    }
                }
            }

            if (!$hasImage) {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $messageContent
                ];
            }
        }

        return $messages;
    }
}