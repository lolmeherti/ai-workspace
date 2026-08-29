<?php

namespace App\Agents;

use App\AgentManager;
use App\JsonParser;

class SchedulingAgent
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function analyzeTask(string $content, string $dueString, array $existingTasks): array
    {
        $currentDate = date('l, F j, Y (H:i)');
        $existingTasksStr = "";
        foreach ($existingTasks as $t) {
            $tDue = isset($t['due']['datetime']) ? $t['due']['datetime'] : (isset($t['due']['date']) ? $t['due']['date'] : 'No due date');
            $existingTasksStr .= "- \"" . $t['content'] . "\" (Due: " . $tDue . ")\n";
        }

        $analysisPrompt = "You are a precise calendar conflict resolver. Today is " . $currentDate . ".\n";
        $analysisPrompt .= "Check if scheduling \"" . $content . "\" on \"" . $dueString . "\" duplicates or overlaps with existing tasks.\n";
        $analysisPrompt .= "Evaluate semantically (e.g. haircut matches barbershop at same time).\n\n";
        $analysisPrompt .= "Existing tasks:\n" . $existingTasksStr . "\n\n";
        $analysisPrompt .= "Respond ONLY in this format:\n";
        $analysisPrompt .= "{\n  \"status\": \"clear\",\n  \"analysis\": \"Brief explanation of overlap.\"\n}";

        $messages = [
            ['role' => 'system', 'content' => $analysisPrompt]
        ];

        $rawResponse = $this->agent->chat($messages, false);
        return JsonParser::extractAndDecode($rawResponse) ?: [];
    }
}
