<?php

namespace App\Services\Tools;

use App\Agents\TaskMatcher;

class DeleteTodoistTaskTool
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
                $emit('token', ['chunk' => "\n\nSearching for matching tasks to delete..."]);

                $query = $toolData['query'] ?? '';
                if (empty($query)) {
                    throw new \Exception("A search query is required to locate the task.");
                }

                $response = $this->todoist->request('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $replyText = '';
                if (empty($tasks)) {
                    $replyText = "\n\n**No active tasks found in your Todoist account.**";
                } else {
                    $taskMatcher = new TaskMatcher($this->agent);
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
    }
}
