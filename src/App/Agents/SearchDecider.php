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
        
        $systemPrompt = <<<TEXT
Today is {$currentDate}.

Return ONLY JSON matching this schema:
{
  "requires_search": boolean,
  "search_query": string or null
}

Set requires_search to true only if the user query requires up-to-date live facts, current weather, or news.
TEXT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $temperature = (float) Config::get('AGENT_DECIDER_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

        if (is_array($data) && isset($data['requires_search']) && $data['requires_search'] === true) {
            return !empty($data['search_query']) ? trim($data['search_query']) : null;
        }

        return null;
    }
}