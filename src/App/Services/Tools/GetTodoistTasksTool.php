<?php

namespace App\Services\Tools;

use App\Config;
use App\Agents\TaskMatcher;

class GetTodoistTasksTool
{
    use ToolStreamHelper;

    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
                $emit('token', ['chunk' => "\n\nRetrieving tasks from Todoist..."]);

                $response = $this->todoist->request('GET', '/tasks');
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

                    $taskMatcher = new TaskMatcher($this->agent);
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
    }
}
