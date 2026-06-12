<?php

namespace App\Services\Tools;

use App\Agents\SchedulingAgent;

class CreateTodoistTaskTool
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
                $emit('token', ['chunk' => "\n\nAnalyzing calendar schedule..."]);
                
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
                    $aiCommentary = "\n\n### âš ï¸ Calendar " . ucfirst($analysis['status']) . " Detected\n\n";
                    $aiCommentary .= $analysis['analysis'] . "\n\n";
                    $aiCommentary .= "Please tell me how you would like to proceed (e.g., reschedule, skip, or create it anyway)!";

                    $emit('token', ['chunk' => $aiCommentary]);

                    return $cleanJson . "\n\n" . $aiCommentary;
                }

                $postData = ['content' => $content];
                if (!empty($dueString)) {
                    $postData['due_string'] = $dueString;
                }

                $task = $this->todoist->request('POST', '/tasks', $postData);

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
    }
}
