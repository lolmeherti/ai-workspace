<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class ContextCondenser
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function condense(array $scrapedPages, string $userPrompt): string
    {
        if (empty($scrapedPages)) {
            return "";
        }

        $summary = "";

        foreach ($scrapedPages as $index => $pageText) {
            if ($index === 0) {
                $summary = $this->initialSummary($pageText, $userPrompt);
            } else {
                $summary = $this->updateSummary($summary, $pageText, $userPrompt);
            }
        }

        return $summary;
    }

    private function initialSummary(string $text, string $userPrompt): string
    {
        $systemPrompt = "You are an assistant organizing context for yourself. Read the provided web text and extract the information most relevant to the user's prompt. Keep your notes concise and factual.";
        
        $userMessage = "User Prompt: " . $userPrompt . "\n\nWeb Text:\n" . $text;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CONDENSER_TEMP', 0.4);
        return $this->agent->chat($messages, false, null, $temperature);
    }

    private function updateSummary(string $currentSummary, string $newText, string $userPrompt): string
    {
        $systemPrompt = "You are an assistant organizing context for yourself. You have existing notes and new web text. Update your notes by incorporating any new, relevant facts from the web text that help answer the user prompt. Discard useless information.";
        
        $userMessage = "User Prompt: " . $userPrompt . "\n\nExisting Notes:\n" . $currentSummary . "\n\nNew Web Text:\n" . $newText;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CONDENSER_TEMP', 0.4);
        return $this->agent->chat($messages, false, null, $temperature);
    }
}