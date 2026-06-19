<?php

namespace App\Services;

use App\Agents\MemorySelector;
use App\Config;

class PromptAssemblyService
{
    private ?MemorySelector $memorySelector;
    private string $uploadDir;
    private \App\Database $db;

    public function __construct(\App\Database $db, ?MemorySelector $memorySelector, string $uploadDir)
    {
        $this->db = $db;
        $this->memorySelector = $memorySelector;
        $this->uploadDir = $uploadDir;
    }

    public function buildSystemPrompt(string $condensedContext, bool $usedCache, string $query, bool $suppressTools = false): string
    {
        $profileData = $this->db->query("SELECT profile_text FROM user_profiles WHERE id = 1");
        $stableProfile = !empty($profileData) ? $profileData[0]['profile_text'] : '';

        $systemPrompt = "";
        if (!empty($stableProfile)) {
            $systemPrompt .= "USER IDENTITY AND CORE CONSTRAINTS:\n{$stableProfile}\n\n";
        }

        $systemPrompt .= <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant. You are being supplemented with up to date data from a third party source via content injection. 
This is what keeps you up to date. If you see data from what you perceive to be the future, don't worry about it and understand that its relatively reliable data.

TEXT;

        if (!$suppressTools) {
            $systemPrompt .= <<<TEXT
If the user asks for a file, asks to recall a document/image, or asks if you have a file on disk, do not make assumptions. You can search the database of uploaded files by outputting a JSON block with the following exact format as your ONLY output:
{"tool": "search_files", "query": "cv, resume"}

If the user wants to schedule a reminder, set a task, plan an appointment, create a task or see their upcoming calendar/schedule, you can help the user easily.
In this scenario your reply MUST BE only one of the following JSON blocks.

To create a task:
{"tool": "create_todoist_task", "content": "clean task summary here", "due_string": "tomorrow at 3pm"}

To fetch upcoming tasks or search for a specific scheduled appointment/reminder:
{"tool": "get_todoist_tasks"}

To update or edit an existing task's title, date, or time:
{"tool": "update_todoist_task", "query": "search words here", "new_content": "new title", "new_due_string": "new date/time"}

To delete or remove a task/reminder:
{"tool": "delete_todoist_task", "query": "search words here"}

If you need to retrieve specific information from your long-term memories about the user that is NOT already present in the "USER IDENTITY" section at the top of this prompt, use the following tool:
{"tool": "search_memories", "query": "specific search query for memories"}

Do not confirm that you've done a tool call. Only do the tool call and nothing else.

TEXT;
        }

        $systemPrompt .= <<<TEXT
INSTRUCTIONS FOR PRE-VETTED REMINDERS:
If the system provides you with pre-vetted suggestion tags (e.g. `[TodoistSuggest: content | due_string]`), you MUST output those exact tags at the very end of your final response so the user can review and click them.
TEXT;

        $now = time();
        $roundedMinute = (int)date('i', $now) >= 30 ? 30 : 0;
        $currentDate = date('l, F j, Y', $now) . sprintf(' (%02d:%02d)', (int)date('H', $now), $roundedMinute);
        $cutoffDate = 'early 2024';
        $systemPrompt .= "\n\nToday's date and approximate current time is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}.\n";

        if (!empty($condensedContext)) {
            $systemPrompt .= "\n\nLIVE WEB SEARCH CONTEXT:\n{$condensedContext}\n";
            if ($usedCache) {
                $systemPrompt .= "\n(Note: This context was retrieved from your recent semantic memory cache).\n";
            }
        }

        return $systemPrompt;
    }

    public function preprocessHistory(array $history): array
    {
        $merged = [];
        $pendingData = '';

        // Pass 1: Collect and strip data_fetching messages, and filter out intermediate tool calls
        foreach ($history as $msg) {
            if (($msg['message_type'] ?? 'text') === 'data_fetching') {
                $pendingData .= "\n\n[SYSTEM DATA FETCHED]\n"
                              . "Tool Executed: " . ($msg['tool_name'] ?? 'unknown_tool') . "()\n"
                              . "Status: Success\n"
                              . "Result Payload:\n"
                              . "-----------------\n"
                              . $msg['message'] . "\n"
                              . "-----------------";
                continue;
            }

            // Skip intermediate assistant tool calls to prevent them from terminating the dialogue chain
            if ($msg['role'] === 'assistant' && 
                (($msg['message_type'] ?? '') === 'tool_call' || 
                 stripos($msg['message'], '"tool"') !== false)) {
                continue;
            }

            $merged[] = $msg;
        }

        // Pass 2: Append collected data to the LAST user message
        if (!empty($pendingData)) {
            for ($i = count($merged) - 1; $i >= 0; $i--) {
                if ($merged[$i]['role'] === 'user') {
                    $merged[$i]['message'] .= "\n\n" . $pendingData
                        . "\n\nRespond to the user's query using the above retrieved information.";
                    break;
                }
            }
        }

        // Pass 3: Collapse consecutive assistant messages (strict template alternation)
        $collapsed = [];
        foreach ($merged as $msg) {
            $lastIdx = count($collapsed) - 1;
            if ($lastIdx >= 0
                && $collapsed[$lastIdx]['role'] === 'assistant'
                && $msg['role'] === 'assistant') {
                $collapsed[$lastIdx]['message'] .= "\n\n" . $msg['message'];
            } else {
                $collapsed[] = $msg;
            }
        }

        return $collapsed;
    }

    public function buildMessagesArray(string $systemPrompt, array $history, string $intent = 'none'): array
    {
        $history = $this->preprocessHistory($history);

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];

        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentHistory = array_slice($history, -$rollingLimit);

        $lastUserIdx = -1;
        foreach ($recentHistory as $idx => $row) {
            if ($row['role'] === 'user') {
                $lastUserIdx = $idx;
            }
        }

        foreach ($recentHistory as $idx => $row) {
            $hasImage = false;
            $messageContent = $row['message'];

            if (preg_match_all('/\[File:\s*([a-zA-Z0-9._\-]+)\]/', $messageContent, $matches)) {
                foreach ($matches[1] as $matchIdx => $matchedFilename) {
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
                            
                            $this->maybeAddRoutingHint($messages, $idx, $lastUserIdx, $intent);
                            $messages[] = [
                                'role' => $row['role'],
                                'content' => [
                                    ['type' => 'text', 'text' => str_replace($matches[0][$matchIdx], '', $messageContent)],
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
                        
                        $this->maybeAddRoutingHint($messages, $idx, $lastUserIdx, $intent);
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
                $this->maybeAddRoutingHint($messages, $idx, $lastUserIdx, $intent);
                
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $messageContent
                ];
            }
        }

        return $messages;
    }

    private function maybeAddRoutingHint(array &$messages, int $idx, int $lastUserIdx, string $intent): void
    {
        if ($idx === $lastUserIdx && $intent !== 'none') {
            $messages[] = [
                'role' => 'system',
                'content' => "[System Routing Hint: Active intent is classified as '{$intent}'. You must focus exclusively on utilizing the corresponding tool instructions defined in your system prompt. Do not call other tools.]"
            ];
        }
    }
}