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

    public function extractBriefingSuggestions(array $emails, array $existingTasks): array
    {
        $currentDate = date('l, F j, Y (H:i)');
        $tomorrowDate = date('l, F j, Y', strtotime('+1 day'));
        
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

        $briefingExtractorPrompt = "You are an expert task extraction sub-agent. Today is " . $currentDate . " (Tomorrow is " . $tomorrowDate . ").\n";
        $briefingExtractorPrompt .= "Scan these emails ONLY for valid future personal commitments, pickups, reservations, meetings, or critical to-dos.\n";
        $briefingExtractorPrompt .= "STRICTLY IGNORE: promotional offers, marketing spam, newsletters, advertisements, shipping updates, receipts, or automated alerts.\n";
        $briefingExtractorPrompt .= "Skip duplicates present on the calendar.\n\n";
        $briefingExtractorPrompt .= "Emails:\n" . $emailsStr . "\n";
        $briefingExtractorPrompt .= "Calendar:\n" . $existingTasksStr . "\n\n";
        $briefingExtractorPrompt .= "Return ONLY a JSON array of suggestions:\n";
        $briefingExtractorPrompt .= "[\n  {\n    \"content\": \"Brief title (e.g., Ebi 7 Reservation)\",\n    \"due_string\": \"Relative time (e.g. tomorrow at 11:30am)\"\n  }\n]\n";
        $briefingExtractorPrompt .= "If none, return `[]`.";

        $messages = [
            ['role' => 'system', 'content' => $briefingExtractorPrompt]
        ];

        $rawResponse = $this->agent->chat($messages, false);
        return JsonParser::extractAndDecode($rawResponse) ?: [];
    }
}