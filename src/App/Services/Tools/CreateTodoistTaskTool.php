<?php

namespace App\Services\Tools;

use App\Agents\SchedulingAgent;

class CreateTodoistTaskTool
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

                
                $content = $toolData['content'] ?? '';
                $dueString = $toolData['due_string'] ?? null;

                if (empty($content)) {
                    throw new \Exception("Task content is required.");
                }

                $response = $this->todoist->request('GET', '/tasks');
                $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

                $schedulingAgent = new SchedulingAgent($this->agent);
                $analysis = $schedulingAgent->analyzeTask($content, $dueString, $tasks);

                if (is_array($analysis) && isset($analysis['status']) && $analysis['status'] !== 'clear') {
                    $instructions = "System scheduling analysis:\n";
                    $instructions .= "Status: " . $analysis['status'] . "\n";
                    $instructions .= $analysis['analysis'] . "\n\n";
                    $instructions .= "[SYSTEM NOTE]: The scheduling agent detected a conflict. Present this analysis to the user and ask how they'd like to proceed (reschedule, skip, or create anyway).";
                    return $instructions;
                }

                $postData = ['content' => $content];
                if (!empty($dueString)) {
                    $postData['due_string'] = $dueString;
                }

                $task = $this->todoist->request('POST', '/tasks', $postData);

                $dueFormatted = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
                
                $taskUrl = $task['url'] ?? (isset($task['id']) ? "https://todoist.com/showTask?id=" . $task['id'] : "https://todoist.com");

                $instructions = "System successfully created the task in Todoist:\n";
                $instructions .= "- Task: \"{$task['content']}\"\n";
                $instructions .= "- ID: {$task['id']}\n";
                $instructions .= "- Due: {$dueFormatted}\n";
                $instructions .= "- Link: {$taskUrl}\n\n";
                $instructions .= "[SYSTEM NOTE]: Present a short, friendly confirmation message to the user confirming the task details. Keep it brief.";

                return $instructions;
    }
}