<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class SearchDecider
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function requiresSearch(string $userPrompt): ?string
    {
        $currentDate = date('l, F j, Y g:i A');
        $systemPrompt = "You are a decision agent for an AI assistant. The current date and time is {$currentDate}. Evaluate the user's prompt to determine if it requires searching the live internet for up-to-date facts, news, weather, or specific information that might be outside your standard training data. If a web search is needed, reply ONLY with the highly optimized search query string. If no search is needed, reply EXACTLY with the word: NONE.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.3);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strtoupper($response) === 'NONE') {
            return null;
        }

        return $response;
    }
}