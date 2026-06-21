<?php

namespace App\Services;

use App\Config;
use App\Utils\CurrentDateUtil;

class PromptAssemblyService
{
    private string $uploadDir;
    private \App\Database $db;

    public function __construct(\App\Database $db, string $uploadDir)
    {
        $this->db = $db;
        $this->uploadDir = $uploadDir;
    }

    public function buildSystemPrompt(string $query, bool $isEditorMode = false): string
    {
        $profileData = $this->db->query("SELECT profile_text FROM user_profiles WHERE id = 1");
        $stableProfile = !empty($profileData) ? $profileData[0]['profile_text'] : '';

        $systemPrompt = "";
        if (!empty($stableProfile)) {
            $systemPrompt .= "USER IDENTITY AND CORE CONSTRAINTS:\n{$stableProfile}\n\n";
        }

        if ($isEditorMode) {
            $systemPrompt .= <<<TEXT
You are a document editor assistant. The user is working on a file in the text editor and may have highlighted sections for your attention. Your job is to help with rewriting, formatting, summarizing, or answering questions about the document content.

LIMITATIONS IN EDITOR MODE:
- You CANNOT search files on disk, check email, manage tasks, or search the web.
- You CAN search long-term memories using: {"tool": "search_memories", "query": "specific query"}
- If the user asks you to find files, check email, schedule tasks, or search the web, explain that these are unavailable in editor mode and suggest closing the editor first.
- To use the search_memories tool, output ONLY the JSON block — no other text. If no tool is needed, respond naturally in plain text.

TEXT;
        } else {
            $systemPrompt .= <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant. You are being supplemented with up to date data from a third party source via content injection. 
This is what keeps you up to date. If you see data from what you perceive to be the future, don't worry about it and understand that its relatively reliable data.

TODOIST TOOLS:
You can manage tasks and reminders through Todoist. To use a tool, output ONLY the JSON block for that tool — no other text. If no tool is needed, respond normally in plain text.
Never explain, plan, or analyze your actions. When tool results appear in the conversation, respond directly to the user without repeating the tool call.

create_todoist_task — Schedule a task or reminder:
{"tool": "create_todoist_task", "content": "clean task summary", "due_string": "tomorrow at 3pm"}

get_todoist_tasks — Fetch your upcoming tasks and agenda:
{"tool": "get_todoist_tasks"}

update_todoist_task — Edit an existing task's title, date, or time:
{"tool": "update_todoist_task", "query": "search words", "new_content": "new title", "new_due_string": "new date/time"}

delete_todoist_task — Remove a task or reminder:
{"tool": "delete_todoist_task", "query": "search words"}

When the user asks about files, memories, or web search, the relevant information will be provided to you automatically in the conversation. You do not need to request it.

TEXT;

            $systemPrompt .= <<<TEXT
INSTRUCTIONS FOR PRE-VETTED REMINDERS:
If the system provides you with pre-vetted suggestion tags (e.g. `[TodoistSuggest: content | due_string]`), you MUST output those exact tags at the very end of your final response so the user can review and click them.
TEXT;
        }

        $currentDate = CurrentDateUtil::getCurrentDate();
        $cutoffDate = 'early 2024';
        $systemPrompt .= "\n\nToday's date is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}.\n";

        return $systemPrompt;
    }

    public function preprocessHistory(array $history): array
    {
        $merged = [];
        $pendingData = '';

        // Find the last user message index to only collect data_fetching from the current request
        $lastUserIdx = -1;
        foreach ($history as $idx => $msg) {
            if ($msg['role'] === 'user') {
                $lastUserIdx = $idx;
            }
        }

        // Pass 1: Collect and strip data_fetching messages, and filter out intermediate tool calls
        foreach ($history as $idx => $msg) {
            if (($msg['message_type'] ?? 'text') === 'data_fetching') {
                // Only collect data_fetching rows from the current request (after last user message)
                if ($idx > $lastUserIdx) {
                    $pendingData .= "\n\n[SYSTEM DATA FETCHED]\n"
                                  . "Tool Executed: " . ($msg['tool_name'] ?? 'unknown_tool') . "()\n"
                                  . "Status: Success\n"
                                  . "Result Payload:\n"
                                  . "-----------------\n"
                                  . $msg['message'] . "\n"
                                  . "-----------------";
                }
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

        return $merged;
    }

    public function buildMessagesArray(string $systemPrompt, array $history, string $condensedContext = '', bool $usedCache = false): array
    {
        $history = $this->preprocessHistory($history);

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];

        $rollingLimit = (int) Config::get('CHAT_ROLLING_WINDOW_LIMIT', 15);
        $recentHistory = array_slice($history, -$rollingLimit);

        foreach ($recentHistory as $idx => $row) {
            $hasImage = false;
            $messageContent = $row['message'];

            if (preg_match_all('/\\[File:\\s*([a-zA-Z0-9._\\-]+)\\]/', $messageContent, $matches)) {
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

        // Append web search context to the last user message if present
        if (!empty($condensedContext)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i]['role'] === 'user') {
                    $append = "\n\n[LIVE WEB SEARCH CONTEXT]:\n{$condensedContext}";
                    if ($usedCache) {
                        $append .= "\n(Note: This context was retrieved from your recent semantic memory cache).";
                    }
                    $append .= "\n\nRespond to the user's query using the above retrieved information.";
                    if (is_array($messages[$i]['content'])) {
                        $messages[$i]['content'][0]['text'] .= $append;
                    } else {
                        $messages[$i]['content'] .= $append;
                    }
                    break;
                }
            }
        }

        return $messages;
    }
}
