<?php

namespace App\Agents;

use App\AgentManager;
use App\JsonParser;
use App\Utils\CurrentDateUtil;

class SchedulingAgent
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function analyzeTask(string $content, string $dueString, array $existingTasks): array
    {
        $currentDate = CurrentDateUtil::getCurrentDate();
        $currentTime = CurrentDateUtil::getCurrentTime();
        $existingTasksStr = "";
        foreach ($existingTasks as $t) {
            $tDue = isset($t['due']['datetime']) ? $t['due']['datetime'] : (isset($t['due']['date']) ? $t['due']['date'] : 'No due date');
            $existingTasksStr .= "- \"" . $t['content'] . "\" (Due: " . $tDue . ")\n";
        }

        $systemPrompt = "You are a precise calendar conflict resolver.\n";
        $systemPrompt .= "Check if scheduling a task duplicates or overlaps with existing tasks.\n";
        $systemPrompt .= "Evaluate semantically (e.g. haircut matches barbershop at same time).\n\n";
        $systemPrompt .= "Respond ONLY in this format:\n";
        $systemPrompt .= "{\n  \"status\": \"clear\",\n  \"analysis\": \"Brief explanation of overlap.\"\n}";

        $userMessage = "Today is {$currentDate}. The current time is {$currentTime}.\n\n";
        $userMessage .= "New task: \"" . $content . "\" on \"" . $dueString . "\"\n\n";
        $userMessage .= "Existing tasks:\n" . $existingTasksStr . "\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $rawResponse = $this->agent->chat($messages, false);
        return JsonParser::extractAndDecode($rawResponse) ?: [];
    }

    public function extractBriefingSuggestions(array $emails, array $existingTasks): array
    {
        $currentDate = CurrentDateUtil::getCurrentDate();
        $currentTime = CurrentDateUtil::getCurrentTime();
        $tomorrowDate = CurrentDateUtil::getTomorrowDate();
        
        $emailsStr = "";
        foreach ($emails as $email) {
            if (isset($email['error'])) continue;
            $emailsStr .= "From: " . $email['from'] . " | Subject: \"" . $email['subject'] . "\" | Date: " . $email['date'] . "\nContent Preview: " . $email['snippet'] . "\n---\n";
        }

        $existingTasksStr = "";
        foreach ($existingTasks as $t) {
            $tDue = isset($t['due']['datetime']) ? $t['due']['datetime'] : (isset($t['due']['date']) ? $t['due']['date'] : 'No due date');
            $existingTasksStr .= "- \"" . $t['content'] . "\" (Due: " . $tDue . ")\n";
        }

        $systemPrompt = "You are an expert task extraction sub-agent.\n";
        $systemPrompt .= "Scan emails ONLY for valid future personal commitments, pickups, reservations, meetings, or critical to-dos.\n";
        $systemPrompt .= "STRICTLY IGNORE: promotional offers, marketing spam, newsletters, advertisements, shipping updates, receipts, or automated alerts.\n";
        $systemPrompt .= "Skip duplicates present on the calendar.\n\n";
        $systemPrompt .= "Return ONLY a JSON array of suggestions:\n";
        $systemPrompt .= "[\n  {\n    \"content\": \"Brief title (e.g., Ebi 7 Reservation)\",\n    \"due_string\": \"Relative time (e.g. tomorrow at 11:30am)\"\n  }\n]\n";
        $systemPrompt .= "If none, return `[]`.";

        $userMessage = "Today is {$currentDate}. The current time is {$currentTime}. (Tomorrow is {$tomorrowDate}).\n\n";
        $userMessage .= "Emails:\n" . $emailsStr . "\n";
        $userMessage .= "Calendar:\n" . $existingTasksStr . "\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $rawResponse = $this->agent->chat($messages, false);
        return JsonParser::extractAndDecode($rawResponse) ?: [];
    }
}