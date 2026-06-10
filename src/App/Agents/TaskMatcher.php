<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class TaskMatcher
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Dynamically filters a list of raw tasks agentically using a fast LLM pass.
     * Identifies specific task lookups versus general schedule requests.
     */
    public function filterTasks(string $userPrompt, array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        $taskListText = "";
        foreach ($tasks as $idx => $task) {
            $due = isset($task['due']['datetime']) ? $task['due']['datetime'] : (isset($task['due']['date']) ? $task['due']['date'] : 'No due date');
            $taskListText .= "Index [{$idx}]: \"{$task['content']}\" (Due: {$due})\n";
        }

        $systemPrompt = <<<TEXT
You are a Task Matching Agent. Your job is to analyze the user's latest message and a list of raw calendar/Todoist tasks to determine which tasks are directly relevant to what the user is asking.

For example:
- If the user asks "When is my dentist appointment?", you should identify any tasks referring to a dentist, dental clinic, teeth, or appointment with Dr. [Name].
- If the user is asking a general question about their schedule, upcoming tasks, or todo list (and NOT looking for a specific appointment or event), you should return an empty array for "matched_indices", as the main system will default to displaying their upcoming general schedule.

Format your output STRICTLY as a JSON object matching this schema:
{
  "is_specific_lookup": boolean,
  "matched_indices": [integer, ...]
}

Do not output any markdown formatting, backticks (such as ```json), or introductory/concluding text. ONLY output the raw JSON block.

RAW TASKS:
{$taskListText}
TEXT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "User Prompt: " . $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        $data = \App\JsonParser::extractAndDecode($response);

        if (is_array($data) && isset($data['is_specific_lookup']) && $data['is_specific_lookup'] === true && !empty($data['matched_indices'])) {
            $matchedTasks = [];
            foreach ($data['matched_indices'] as $index) {
                if (isset($tasks[$index])) {
                    $matchedTasks[] = $tasks[$index];
                }
            }
            return $matchedTasks;
        }

        return [];
    }
}