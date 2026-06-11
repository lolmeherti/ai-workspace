<?php

namespace App\Services;

use App\Database;
use App\AgentManager;
use App\Config;
use App\Enums\Tool;

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
        $toolType = null;
        $toolData = [];

        $decoded = \App\JsonParser::extractAndDecode($aiResponse);

        if (is_array($decoded) && isset($decoded['tool'])) {
            $resolvedTool = Tool::tryFrom($decoded['tool']);
            if ($resolvedTool !== null) {
                $isToolCall = true;
                $toolType = $resolvedTool;
                $toolData = $decoded;
            }
        }

        if (!$isToolCall) {
            return $aiResponse;
        }

        $cleanJson = json_encode($decoded);

        try {
            if ($toolType === Tool::SEARCH_FILES) {
                $toolQuery = $toolData['query'] ?? '';
                $emit('token', ['chunk' => "\n\nChecking files..."]);

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

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];

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

                return $cleanJson . "\n\n" . $aiCommentary;

            } elseif ($toolType === Tool::CREATE_TODOIST_TASK) {
                $emit('token', ['chunk' => "\n\nCreating task in Todoist..."]);
                
                $content = $toolData['content'] ?? '';
                $dueString = $toolData['due_string'] ?? null;

                if (empty($content)) {
                    throw new \Exception("Task content is required.");
                }

                $postData = ['content' => $content];
                if (!empty($dueString)) {
                    $postData['due_string'] = $dueString;
                }

                $task = $this->makeTodoistRequest('POST', '/tasks', $postData);

                $dueFormatted = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                
                $instructions = "System successfully created the task in Todoist:\n";
                $instructions .= "- Task: \"{$task['content']}\"\n";
                $instructions .= "- ID: {$task['id']}\n";
                $instructions .= "- Due: {$dueFormatted}\n";
                $instructions .= "- Link: {$task['url']}\n\n";
                $instructions .= "[SYSTEM NOTE]: Present a short, friendly confirmation message to the user confirming the task details. Keep it brief.";

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];

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

                return $cleanJson . "\n\n" . $aiCommentary;

            } elseif ($toolType === Tool::GET_TODOIST_TASKS) {
                $emit('token', ['chunk' => "\n\nRetrieving tasks from Todoist..."]);

                $response = $this->makeTodoistRequest('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $instructions = '';
                if (empty($tasks)) {
                    $instructions = "System successfully fetched active tasks from Todoist:\n- No active tasks found.\n";
                } else {
                    $userPrompt = '';
                    for ($i = count($messages) - 1; $i >= 0; $i--) {
                        if ($messages[$i]['role'] === 'user') {
                            $userPrompt = $messages[$i]['content'];
                            break;
                        }
                    }

                    $query = $toolData['query'] ?? $userPrompt;

                    $taskMatcher = new \App\Agents\TaskMatcher($this->agent);
                    $matchedTasks = $taskMatcher->filterTasks($query, $tasks);

                    if (!empty($matchedTasks)) {
                        $instructions = "System searched active tasks for the query: '{$query}'. Here are the matching events found:\n";
                        foreach ($matchedTasks as $task) {
                            $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                            $instructions .= "- [ID: {$task['id']}] \"{$task['content']}\" (Due: {$due})\n";
                        }
                    } else {
                        usort($tasks, function($a, $b) {
                            $aTime = isset($a['due']['datetime']) ? strtotime($a['due']['datetime']) : (isset($a['due']['date']) ? strtotime($a['due']['date']) : PHP_INT_MAX);
                            $bTime = isset($b['due']['datetime']) ? strtotime($b['due']['datetime']) : (isset($b['due']['date']) ? strtotime($b['due']['date']) : PHP_INT_MAX);
                            return $aTime <=> $bTime;
                        });

                        $limit = (int) Config::get('CALENDAR_FETCH_LIMIT', 5);
                        
                        $userMessageLower = strtolower($userPrompt);
                        if (str_contains($userMessageLower, 'all') || str_contains($userMessageLower, 'everything') || str_contains($userMessageLower, 'more')) {
                            $limit = 15;
                        }

                        $instructions = "System successfully fetched upcoming tasks from Todoist:\n";
                        $upcomingTasks = array_slice($tasks, 0, $limit);
                        foreach ($upcomingTasks as $task) {
                            $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                            $instructions .= "- [ID: {$task['id']}] \"{$task['content']}\" (Due: {$due})\n";
                        }
                        
                        if (count($tasks) > $limit) {
                            $remaining = count($tasks) - $limit;
                            $instructions .= "- ...and {$remaining} more tasks are on your list.\n";
                        }
                    }
                }
                
                $instructions .= "\n[SYSTEM NOTE]: Present the search/list results to the user clearly. Keep your response professional, precise, and direct to their query.";

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];

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

                return $cleanJson . "\n\n" . $aiCommentary;

            } elseif ($toolType === Tool::DELETE_TODOIST_TASK) {
                $emit('token', ['chunk' => "\n\nSearching for matching tasks to delete..."]);

                $query = $toolData['query'] ?? '';
                if (empty($query)) {
                    throw new \Exception("A search query is required to locate the task.");
                }

                $response = $this->makeTodoistRequest('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $replyText = '';
                if (empty($tasks)) {
                    $replyText = "\n\n**No active tasks found in your Todoist account.**";
                } else {
                    $taskMatcher = new \App\Agents\TaskMatcher($this->agent);
                    $matchedTasks = $taskMatcher->filterTasks($query, $tasks);

                    if (!empty($matchedTasks)) {
                        if (count($matchedTasks) === 1) {
                            $targetTask = $matchedTasks[0];
                            $due = isset($targetTask['due']['datetime']) ? $targetTask['due']['datetime'] : (isset($targetTask['due']['date']) ? $targetTask['due']['date'] : 'No due date');
                            
                            $replyText = "\n\nI found the following matching task to delete:\n";
                            $replyText .= "- **Task:** \"{$targetTask['content']}\" (Due: {$due})\n\n";
                            $replyText .= "[TodoistDelete:{$targetTask['id']}]";
                        } else {
                            $replyText = "\n\nI found multiple matching tasks to delete. Please specify which one you meant:\n";
                            foreach ($matchedTasks as $task) {
                                $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                                $replyText .= "- \"{$task['content']}\" (Due: {$due})\n";
                                $replyText .= "[TodoistDelete:{$task['id']}]\n";
                            }
                        }
                    } else {
                        $replyText = "\n\nI couldn't find any active tasks matching \"{$query}\" to delete.";
                    }
                }

                $emit('token', ['chunk' => $replyText]);

                return $cleanJson . $replyText;

            } elseif ($toolType === Tool::UPDATE_TODOIST_TASK) {
                $emit('token', ['chunk' => "\n\nSearching for the task to edit..."]);

                $query = $toolData['query'] ?? '';
                $newContent = $toolData['new_content'] ?? null;
                $newDueString = $toolData['new_due_string'] ?? null;

                if (empty($query)) {
                    throw new \Exception("A search query is required to locate the task to update.");
                }

                if (empty($newContent) && empty($newDueString)) {
                    throw new \Exception("Please supply either a 'new_content' or a 'new_due_string' to update.");
                }

                $response = $this->makeTodoistRequest('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $instructions = '';
                if (empty($tasks)) {
                    $instructions = "System successfully searched active tasks from Todoist:\n- No active tasks found.\n";
                } else {
                    $taskMatcher = new \App\Agents\TaskMatcher($this->agent);
                    $matchedTasks = $taskMatcher->filterTasks($query, $tasks);

                    if (!empty($matchedTasks)) {
                        if (count($matchedTasks) === 1) {
                            $targetTask = $matchedTasks[0];
                            $taskId = $targetTask['id'];

                            $updateData = [];
                            if (!empty($newContent)) {
                                $updateData['content'] = $newContent;
                            }
                            if (!empty($newDueString)) {
                                $updateData['due_string'] = $newDueString;
                            }

                            $emit('token', ['chunk' => "\n\nUpdating task details..."]);
                            $updatedTask = $this->makeTodoistRequest('POST', '/tasks/' . $taskId, $updateData);

                            $dueFormatted = isset($updatedTask['due']['datetime']) ? $updatedTask['due']['datetime'] : (isset($updatedTask['due']['date']) ? $updatedTask['due']['date'] : 'No due date');

                            $instructions = "System successfully executed the task update:\n";
                            $instructions .= "- Updated Task ID: {$taskId}\n";
                            $instructions .= "- New Content: \"{$updatedTask['content']}\"\n";
                            $instructions .= "- New Due Date: {$dueFormatted}\n\n";
                            $instructions .= "[SYSTEM NOTE]: Confirm to the user that the task has been successfully updated with the new details. Keep your response brief and positive.";
                        } else {
                            $instructions = "System found MULTIPLE matching tasks for update query '{$query}':\n";
                            foreach ($matchedTasks as $task) {
                                $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                                $instructions .= "- [ID: {$task['id']}] \"{$task['content']}\" (Due: {$due})\n";
                            }
                            $instructions .= "\n[SYSTEM NOTE]: Ask the user to clarify which of these specific tasks they intended to update, as there are multiple matches.";
                        }
                    } else {
                        $instructions = "System searched active tasks for: '{$query}'. No matching tasks found to update.\n";
                        $instructions .= "[SYSTEM NOTE]: Inform the user that you couldn't find any tasks matching '{$query}' to update.";
                    }
                }

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];

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

                return $cleanJson . "\n\n" . $aiCommentary;

            } elseif ($toolType === Tool::GET_EMAIL_BRIEFING) {
                $emit('token', ['chunk' => "\n\nSyncing your inboxes..."]);

                $emailService = new \App\Services\EmailService($this->db);
                // Updated to include the mandatory boolean argument
                $emails = $emailService->fetchRecentEmails(true);

                $instructions = '';
                if (empty($emails)) {
                    $instructions = "System successfully checked your inboxes:\n- No emails found in the last 24 hours across connected accounts.\n";
                } else {
                    $instructions = "System successfully scanned your connected mail accounts. Here are the emails retrieved:\n";
                    foreach ($emails as $email) {
                        if (isset($email['error'])) {
                            $instructions .= "- [Account: {$email['account_label']} ({$email['account_email']})] Connection Error: {$email['error']}\n";
                        } else {
                            $instructions .= "- [Account: {$email['account_label']} ({$email['account_email']})] From: {$email['from']} | Subject: \"{$email['subject']}\" | Date: {$email['date']}\n  Brief Snippet: {$email['snippet']}\n";
                        }
                    }
                }

                $instructions .= "\n[SYSTEM NOTE]: Summarize these emails and present a beautiful daily briefing. Mention each account's status. Keep your response professional, precise, and highly readable. If any accounts had connection errors, mention them gracefully.";

                $messages[] = ['role' => 'assistant', 'content' => $cleanJson];
                $messages[] = [
                    'role' => 'system',
                    'content' => $instructions
                ];

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

                return $cleanJson . "\n\n" . $aiCommentary;
            }

            return $aiResponse;
        } catch (\Throwable $e) {
            $emit('token', ['chunk' => "\n\n**System Error executing tool:** " . $e->getMessage()]);
            return $aiResponse;
        }
    }

    public function makeTodoistRequest(string $method, string $endpoint, ?array $data = null): array
    {
        $apiKey = Config::get('TODOIST_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("Todoist API Key is not configured in your .env file.");
        }

        $ch = curl_init("https://api.todoist.com/api/v1" . $endpoint);
        $headers = [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204) {
            return ['status' => 'success'];
        }

        if ($httpCode >= 400) {
            $decodedResponse = json_decode($response, true);
            $errorMsg = is_array($decodedResponse) && isset($decodedResponse['message']) ? $decodedResponse['message'] : $response;
            throw new \Exception("Todoist API returned error {$httpCode}: " . $errorMsg);
        }

        return json_decode($response, true) ?: [];
    }
}