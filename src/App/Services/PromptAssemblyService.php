<?php

namespace App\Services;

use App\Config;

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
- You CAN search long-term memories using: search_memories QUERY:specific query
- If the user asks you to find files, check email, schedule tasks, or search the web, explain that these are unavailable in editor mode and suggest closing the editor first.
- To use the search_memories tool, output ONLY the  line — no other text. If no tool is needed, respond naturally in plain text.

TEXT;
        } else {
            $systemPrompt .= <<<TEXT
You are a helpful, friendly, and highly intelligent AI conversational assistant with access to super_abilities.

### Context & Temporality
You are supplemented with up-to-date data from third-party sources via content injection. If you see timestamps or information that seem to be from the "future" relative to your training cutoff (such as data from 2026 or later), accept it as reliable, real-time context. Do not argue about the date.

### Information Limitations
Do not explain that as an AI you cannot browse the web or access the user's files, instead if the user asks you to:
- Access real-time data, current events, or live web info
- Look up their personal files, documents, or notes
- View or modify their calendar, todos, or emails
- Perform any action beyond generating text

Simply say that in order to help the user you need access to super_abilities.

TOOL TURN RULE: When you see [State: Tools active] at the end of a user message, tools are already enabled. Do NOT output super_abilities. Instead, output ONLY the tool call in the format shown in that message — no other text.

IMPORTANT: If the information the user is asking for is already available in the conversation history (from previous tool results, data_fetching, or your own earlier responses), answer directly from that context. Do not request super_abilities for information you already possess.

Example:
In order to help you with that, I need super_abilities.

Do not attempt to make up, guess, or hallucinate the information. Instead, you must request assistance by outputting the exact secret keyword:
super_abilities

Keep your response brief when requesting assistance, explaining naturally to the user that you need these capabilities to proceed.

TEXT;
        }

        $now = time();
        $roundedMinute = (int)date('i', $now) >= 30 ? 30 : 0;
        $currentDate = date('l, F j, Y', $now) . sprintf(' (%02d:%02d)', (int)date('H', $now), $roundedMinute);
        $cutoffDate = 'early 2024';
        $systemPrompt .= "\n\nToday's date and approximate current time is {$currentDate}. Your internal knowledge cutoff is {$cutoffDate}.\n";

        return $systemPrompt;
    }

    public function preprocessHistory(array $history): array
    {
        $merged = [];

        foreach ($history as $msg) {
            // Skip intermediate tool-call stubs — machine-readable syntax,
            // not something the model should pattern-match on
            if ($msg['role'] === 'assistant' && 
                (($msg['message_type'] ?? '') === 'tool_call' || 
                 ($msg['message_type'] ?? '') === 'super_abilities')) {
                continue;
            }

            $merged[] = $msg;
        }

        return $merged;
    }

    /**
     * @param array $activeTools
     */
    public function buildMessagesArray(string $systemPrompt, array $history, array $activeTools = [], string $condensedContext = '', bool $usedCache = false, string $currentQuery = ''): array
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

        // Append state guard to the last user message
        $stateGuard = $this->buildStateGuard($activeTools, $currentQuery);

        // Append web search context if provided
        if (!empty($condensedContext)) {
            $stateGuard .= "\n\n[LIVE WEB SEARCH CONTEXT]:\n{$condensedContext}";
            if ($usedCache) {
                $stateGuard .= "\n(Note: This context was retrieved from your recent semantic memory cache).";
            }
            $stateGuard .= "\n\nRespond to the user's query using the above retrieved information.";
        }

        if ($stateGuard !== '') {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i]['role'] === 'user') {
                    if (is_array($messages[$i]['content'])) {
                        $messages[$i]['content'][0]['text'] .= $stateGuard;
                    } else {
                        $messages[$i]['content'] .= $stateGuard;
                    }
                    break;
                }
            }
        }

        return $messages;
    }

    private function buildStateGuard(array $activeTools, string $currentQuery = ''): string
    {
        if (empty($activeTools)) {
            return "\n\n[State: No tools active. The user has not enabled any tools. If the user asks for actions beyond text generation, say super_abilities.]";
        }

        $queryHint = !empty($currentQuery)
            ? "\nThe user's current request is: \"{$currentQuery}\""
            : '';

        $toolList = implode(', ', $activeTools);

        // Per-tool parameter reference
        $toolSpecs = [
            'search_files'     => "search_files QUERY:<SEARCH_TERMS> — space-delimited keywords from the user's request. Include synonyms. Use QUERY: prefix, not query=",
            'search_web'       => "search_web QUERY:<SEARCH_TERMS> — space-delimited keywords. For comparisons or multi-topic questions, use comma-separated queries (e.g. QUERY:iPhone 15 specs, Samsung Galaxy S24 specs). Each runs independently.",
            'search_memories'  => "search_memories QUERY:<SEARCH_TERMS> — space-delimited keywords with synonyms. For multiple distinct topics, use comma-separated queries (e.g. QUERY:my IBAN, my home address). Each runs independently.",
            'get_todoist_tasks'     => 'get_todoist_tasks QUERY:<what to look for>',
            'create_todoist_task'   => 'create_todoist_task QUERY:<task description> DUE_STRING:<natural due date, e.g. "tomorrow at 9am" or "July 9 at 3pm". If the user provided no due date, use your best judgment to pick a reasonable one — never use "ASAP" or vague placeholders.>',
            'update_todoist_task'   => 'update_todoist_task QUERY:<which task to find> NEW_CONTENT:<updated description> NEW_DUE_STRING:<new due date>',
            'delete_todoist_task'   => 'delete_todoist_task QUERY:<which task to delete>',
        ];

        $specLines = [];
        $resolvedTools = [];
        foreach ($activeTools as $tool) {
            if ($tool === 'calendar') {
                $resolvedTools[] = 'get_todoist_tasks';
                $resolvedTools[] = 'create_todoist_task';
                $resolvedTools[] = 'update_todoist_task';
                $resolvedTools[] = 'delete_todoist_task';
                $specLines[] = '  ' . $toolSpecs['get_todoist_tasks'];
                $specLines[] = '  ' . $toolSpecs['create_todoist_task'];
                $specLines[] = '  ' . $toolSpecs['update_todoist_task'];
                $specLines[] = '  ' . $toolSpecs['delete_todoist_task'];
            } elseif (isset($toolSpecs[$tool])) {
                $resolvedTools[] = $tool;
                $specLines[] = '  ' . $toolSpecs[$tool];
            }
        }

        $resolvedList = implode(', ', $resolvedTools);
        $specs = !empty($specLines) ? "\n" . implode("\n", $specLines) : '';

        $toolCount = count($activeTools);
        if ($toolCount > 1) {
            $callInstruction = "Output all relevant tool calls from the list below, one per line. For calendar tools, pick the ONE that matches the user's intent. Replace placeholders with real values:";
        } else {
            $callInstruction = "Output ONLY one tool call from the list below, using keywords from the current request. Replace placeholders with real values:";
        }

        return "\n\n[State: Tools active — {$resolvedList}.{$queryHint}\nDO NOT say super_abilities. DO NOT add any commentary, explanation, or greeting. {$callInstruction}]{$specs}";
    }
}
