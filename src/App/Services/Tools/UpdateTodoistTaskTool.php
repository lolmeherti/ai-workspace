<?php

namespace App\Services\Tools;

use App\Agents\TaskMatcher;

class UpdateTodoistTaskTool
{
    public function __construct(
        private \App\Database $db,
        private \App\AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {

                $query = $toolData['query'] ?? '';
                $newContent = $toolData['new_content'] ?? null;
                $newDueString = $toolData['new_due_string'] ?? null;

                if (empty($query)) {
                    throw new \Exception("A search query is required to locate the task to update.");
                }

                if (empty($newContent) && empty($newDueString)) {
                    throw new \Exception("Please supply either a 'new_content' or a 'new_due_string' to update.");
                }

                $response = $this->todoist->request('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $instructions = '';
                if (empty($tasks)) {
                    $instructions = "System successfully searched active tasks from Todoist:\n- No active tasks found.\n";
                } else {
                    $taskMatcher = new TaskMatcher($this->agent);
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

            
                            $updatedTask = $this->todoist->request('POST', '/tasks/' . $taskId, $updateData);

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

                return $instructions;
    }
}
