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

    public function buildRouterPrompt(string $query): string
    {
        return <<<TEXT
You are a highly accurate task router. Analyze the user's input and identify ALL categories of actions they want to take.

Categories:
- "search_files": User wants to find, look up, view, or check files/documents/images on disk.
- "todoist_create": User wants to create, add, plan, or schedule a task/reminder/appointment.
- "todoist_get": User wants to see, read, fetch, or list their tasks/calendar/schedule.
- "todoist_update": User wants to edit, update, reschedule, or change an existing task/appointment.
- "todoist_delete": User wants to delete, remove, or clear a task/reminder.
- "none": The input is standard conversation or a general question.

Rules:
1. Output a comma-separated list of ALL categories that apply (e.g., "todoist_create, search_files").
2. If the input is just normal conversation, output "none".
3. Do NOT write explanations, markdown, or punctuation other than commas.

User Input: "{$query}"
Categories:
TEXT;
    }

    public function buildSystemPrompt(string $condensedContext, bool $usedCache, string $query): string
    {
        $currentDate = date('l, F j, Y (H:i)');
        $cutoffDate = 'early 2024';

        $systemPrompt = <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant. Today's date and exact current time is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}. You are being supplemented with up to date data from a third party source via content injection. 
This is what keeps you up to date. If you see data from what you perceive to be the future, don't worry about it and understand that its relatively reliable data.

If the user asks for a file, asks to recall a document/image, or asks if you have a file on disk, do not make assumptions. You can search the database of uploaded files by outputting a JSON block with the following exact format as your ONLY output:
{"tool": "search_files", "query": "search words here"}

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

Do not confirm that you've done a tool call. Only do the tool call and nothing else.

INSTRUCTIONS FOR PRE-VETTED REMINDERS:
If the system provides you with pre-vetted suggestion tags (e.g. `[TodoistSuggest: content | due_string]`), you MUST output those exact tags at the very end of your final response so the user can review and click them.
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

    public function buildMessagesArray(string $systemPrompt, array $history, string $intent = 'none'): array
    {
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
                            
                            if ($idx === $lastUserIdx && $intent !== 'none') {
                                $messages[] = [
                                    'role' => 'system',
                                    'content' => "[System Routing Hint: Active intent is classified as '{$intent}'. You must focus exclusively on utilizing the corresponding tool instructions defined in your system prompt. Do not call other tools.]"
                                ];
                            }

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
                        
                        if ($idx === $lastUserIdx && $intent !== 'none') {
                            $messages[] = [
                                'role' => 'system',
                                'content' => "[System Routing Hint: Active intent is classified as '{$intent}'. You must focus exclusively on utilizing the corresponding tool instructions defined in your system prompt. Do not call other tools.]"
                            ];
                        }

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
                if ($idx === $lastUserIdx && $intent !== 'none') {
                    $messages[] = [
                        'role' => 'system',
                        'content' => "[System Routing Hint: Active intent is classified as '{$intent}'. You must focus exclusively on utilizing the corresponding tool instructions defined in your system prompt. Do not call other tools.]"
                    ];
                }

                $messages[] = [
                    'role' => $row['role'],
                    'content' => $messageContent
                ];
            }
        }

        return $messages;
    }
}