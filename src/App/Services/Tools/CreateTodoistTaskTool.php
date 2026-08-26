<?php

namespace App\Services\Tools;

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
        $content = $toolData['content'] ?? $toolData['query'] ?? '';
        $dueString = $toolData['due_string'] ?? $toolData['due_date'] ?? null;

        if (!empty($dueString) && preg_match('/^(?:asap|whenever|no\s+rush|when\s+possible)$/i', trim($dueString))) {
            $dueString = 'today';
        }

        if (empty($content)) {
            throw new \Exception("Task content is required.");
        }

        $response = $this->todoist->request('GET', '/tasks');
        $tasks = isset($response['results']) ? $response['results'] : (is_array($response) ? $response : []);

        $conflict = TodoistApiClient::detectConflict($tasks, $content, $dueString);
        if ($conflict !== null) {
            $existing = $conflict['task'];
            $existingDue = $existing['due']['datetime'] ?? $existing['due']['date'] ?? 'No due date';

            $note = "System conflict while creating Todoist task \"{$content}\": an existing task \""
                . ($existing['content'] ?? '') . "\" (due: {$existingDue}) matches.\n\n"
                . "[SYSTEM NOTE]: Do NOT claim the task was created. Briefly tell the user a matching or overlapping task already exists";
            if (!empty($dueString)) {
                $note .= " and append the pre-vetted card verbatim so they can choose to schedule it anyway:\n"
                    . "[TodoistSuggest: {$content} | {$dueString}]";
            } else {
                $note .= ".";
            }
            return $note;
        }

        $postData = ['content' => $content];
        if (!empty($dueString)) {
            $postData['due_string'] = $dueString;
        }

        $task = $this->todoist->request('POST', '/tasks', $postData);

        $dueFormatted = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');

        $taskUrl = $task['url'] ?? (isset($task['id']) ? "https://todoist.com/showTask?id=" . $task['id'] : "https://todoist.com");

        $emit('todoist_created', [
            'content' => $task['content'] ?? $content,
            'due' => $dueFormatted,
            'url' => $taskUrl,
        ]);

        $instructions = "System successfully created the task in Todoist:\n";
        $instructions .= "- Task: \"{$task['content']}\"\n";
        $instructions .= "- ID: {$task['id']}\n";
        $instructions .= "- Due: {$dueFormatted}\n";
        $instructions .= "- Link: {$taskUrl}\n\n";
        $instructions .= "[SYSTEM NOTE]: Present a short, friendly confirmation message to the user confirming the task details. Keep it brief.";

        return $instructions;
    }
}
