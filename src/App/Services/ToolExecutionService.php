<?php

namespace App\Services;

use App\Database;
use App\AgentManager;

class ToolExecutionService
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

    public function processToolCall(string $aiResponse, int $sessionId, array $messages, callable $emit): string
    {
        $isToolCall = false;
        $toolQuery = '';

        // 1. Find the first open curly brace '{'
        $startIndex = strpos($aiResponse, '{');
        if ($startIndex !== false) {
            // 2. Find the first double quotes '"' after '{'
            $firstQuote = strpos($aiResponse, '"', $startIndex);
            if ($firstQuote !== false) {
                // 3. Find the closing key quote '"'
                $secondQuote = strpos($aiResponse, '"', $firstQuote + 1);
                if ($secondQuote !== false) {
                    // 4. Find the colon ':'
                    $colon = strpos($aiResponse, ':', $secondQuote + 1);
                    if ($colon !== false) {
                        // 5. Find the space and opening mark (e.g., ", {, [, digit, or word)
                        $remaining = substr($aiResponse, $colon + 1);
                        if (preg_match('/^\s*([{"\[\d\w])/', $remaining)) {
                            // Start verified!
                            // 6. Start from the back and find the first closed curly brace '}'
                            $endIndex = strrpos($aiResponse, '}');
                            if ($endIndex !== false && $endIndex > $startIndex) {
                                // 7. Extract the complete JSON block
                                $jsonStr = substr($aiResponse, $startIndex, $endIndex - $startIndex + 1);
                                $decoded = @json_decode($jsonStr, true);

                                if (is_array($decoded) && isset($decoded['tool']) && $decoded['tool'] === 'search_files') {
                                    $isToolCall = true;
                                    $toolQuery = $decoded['query'] ?? '';
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!$isToolCall) {
            return $aiResponse;
        }

        try {
            $keywords = array_filter(explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $toolQuery)));
            
            $sql = "SELECT id, original_name, physical_name, generated_title, file_type, uploaded_at FROM uploaded_files";
            $conditions = [];
            $params = [];
            
            if (!empty($keywords)) {
                $idx = 0;
                foreach ($keywords as $word) {
                    if (mb_strlen($word) < 2) continue;
                    $conditions[] = "(generated_title LIKE :w{$idx} OR original_name LIKE :o{$idx})";
                    $params[":w{$idx}"] = "%{$word}%";
                    $params[":o{$idx}"] = "%{$word}%";
                    $idx++;
                }
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" OR ", $conditions);
            }
            
            $sql .= " ORDER BY uploaded_at DESC LIMIT 5";
            $matchingFiles = $this->db->query($sql, $params);

            $resultsTxt = "System search completed for '{$toolQuery}'. Matches found on disk:\n";
            $injectedContexts = [];
            $imageAttachments = [];

            if (empty($matchingFiles)) {
                $resultsTxt .= "- No matching files found.\n";
            } else {
                foreach ($matchingFiles as &$f) {
                    $f['preview'] = '';
                    $resultsTxt .= "- File: \"{$f['generated_title']}\" (Original Name: {$f['original_name']}, Physical Path: uploads/{$f['physical_name']}, Uploaded at: {$f['uploaded_at']}, Type: {$f['file_type']})\n";

                    if ($f['file_type'] !== 'image') {
                        $txtPath = $this->uploadDir . $f['physical_name'] . '.txt';
                        if (file_exists($txtPath)) {
                            $docText = file_get_contents($txtPath);
                            $f['preview'] = mb_substr($docText, 0, 300, 'UTF-8');
                            $injectedContexts[] = "[Content of Found Document \"{$f['generated_title']}\" (uploads/{$f['physical_name']})]:\n{$docText}\n[End Document Content]";
                        }
                    } else {
                        $imgPath = $this->uploadDir . $f['physical_name'];
                        if (file_exists($imgPath)) {
                            $mimeType = @mime_content_type($imgPath) ?: 'image/jpeg';
                            $base64 = base64_encode(file_get_contents($imgPath));
                            $imageAttachments[] = [
                                'mime_type' => $mimeType,
                                'base64' => $base64,
                                'physical_name' => $f['physical_name'],
                                'title' => $f['generated_title']
                            ];
                        }
                    }
                }
                unset($f);
            }

            $instructions = <<<TEXT
Below are the results of the file search you just requested.

{$resultsTxt}
TEXT;

            if (!empty($injectedContexts)) {
                $injectedContextsStr = implode("\n\n", $injectedContexts);
                $instructions .= <<<TEXT


[SYSTEM NOTE]: The content of matching text documents has been extracted and supplied below:
{$injectedContextsStr}
[End Document Content]
TEXT;
            }

            if (!empty($imageAttachments)) {
                $instructions .= <<<TEXT


[SYSTEM NOTE]: The matching image files have been projected directly to your vision context. You can see them fully.
TEXT;
            }

            $instructions .= <<<TEXT


CRITICAL RESPONSE INSTRUCTIONS:
1. Do not ask the user to upload or reference/attach the files. You already have full access to their contents/images right now in this context.
2. Analyze the supplied files and answer the user's original query fully and directly.
3. You MUST refer to the file(s) in your response using the exact bracketed tag format `[File: physical_name]` (e.g., '[File: 1780675239_image.jpeg]'). This tag is caught by the frontend parser to automatically display the actual image or file card inline to the user.
4. Keep your answer professional, direct, and concise.
TEXT;

            $messages[] = ['role' => 'assistant', 'content' => $aiResponse];

            if (!empty($imageAttachments)) {
                $contentItems = [
                    ['type' => 'text', 'text' => $instructions]
                ];
                foreach ($imageAttachments as $img) {
                    $contentItems[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$img['mime_type']};base64,{$img['base64']}"
                        ]
                    ];
                }
                $messages[] = [
                    'role' => 'system',
                    'content' => $contentItems
                ];
            } else {
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];
            }

            $emit('token', ['chunk' => "\n\nChecking files..."]);

            $aiCommentary = '';
            $commentaryBuffer = '';

            $this->agent->chat($messages, true, function($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
                $aiCommentary .= $chunk;
                $commentaryBuffer .= $chunk;

                if (mb_check_encoding($commentaryBuffer, 'UTF-8')) {
                    $emit('token', ['chunk' => $commentaryBuffer]);
                    $commentaryBuffer = '';
                }
            });

            if (!empty($commentaryBuffer)) {
                $emit('token', ['chunk' => mb_convert_encoding($commentaryBuffer, 'UTF-8', 'UTF-8')]);
            }

            $emit('file_choices', [
                'files' => $matchingFiles,
                'query' => $toolQuery
            ]);

            return $aiResponse . "\n\n" . $aiCommentary;
        } catch (\Throwable $e) {
            $emit('token', ['chunk' => "\n\n**System Error retrieving files:** " . $e->getMessage()]);
            return $aiResponse;
        }
    }
}