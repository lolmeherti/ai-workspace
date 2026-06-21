<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

class SearchQueryRefiner
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function refine(string $currentQuery, array $history): ?string
    {
        $historyText = $this->formatHistory($history);

        $systemPrompt = <<<TEXT
You are a Search Query Refinement Agent. Your job is to decide whether a web search is useful for the user's latest message, and if so, produce a focused, search-engine-ready query.

Rules:
- Use the recent conversation history to understand context.
- If the latest message is purely conversational filler, social, or contains no real information need (e.g., "sounds good", "ok", "thanks", "I agree"), set should_search to false.
- If the message asks about personal data that a public web search cannot access (e.g., "my flight", "my booking", "my bank account", "my order status"), set should_search to false unless the user explicitly asks for general public help.
- If the message mixes filler with a real question, strip the filler and return only the information need as search_query.
- The search_query must be concise and contain no conversational preamble.

Output STRICTLY as a JSON object matching this schema:
{
  "should_search": true | false,
  "search_query": "string or null",
  "reason": "brief reason"
}
TEXT;

        $userMessage = "Recent conversation history:\n{$historyText}\n\nLatest user message: \"{$currentQuery}\"\n\nDecide if a web search is useful and produce a focused query.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_SEARCH_REFINER_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        $data = \App\JsonParser::extractAndDecode($response);

        if (!is_array($data) || !isset($data['should_search'])) {
            return $currentQuery;
        }

        if ($data['should_search'] === false || $data['should_search'] === 'false') {
            return null;
        }

        $searchQuery = $data['search_query'] ?? '';
        if (is_string($searchQuery) && trim($searchQuery) !== '') {
            return trim($searchQuery);
        }

        return $currentQuery;
    }

    private function formatHistory(array $history): string
    {
        $limit = (int) Config::get('SEARCH_REFINER_HISTORY_LIMIT', 10);
        $sliced = array_slice($history, -$limit);

        $lines = [];
        foreach ($sliced as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['message'] ?? ($msg['content'] ?? '');
            $lines[] = ucfirst($role) . ': ' . $content;
        }

        return implode("\n", $lines);
    }
}
