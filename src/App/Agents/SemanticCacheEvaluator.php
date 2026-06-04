<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;
use App\Cache;

class SemanticCacheEvaluator
{
    private AgentManager $agent;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
    }

    public function evaluate(string $newSearchQuery, array $ledger): ?array
    {
        if (empty($ledger)) {
            return null;
        }

        $currentDate = date('l, F j, Y g:i A');
        
        $ledgerText = "";
        foreach ($ledger as $index => $item) {
            $rawContent = Cache::get($item['cache_key']) ?? '';

            $ledgerText .= "ID: {$index}\nQuery: \"{$item['query']}\"\nCache:\n\"{$rawContent}\"\n";
            $ledgerText .= "--------------------------------------------------\n";
        }

        $systemPrompt = <<<TEXT
Today is {$currentDate}.

Return ONLY JSON matching this schema:
{
  "decision": "AUTO_USE" | "ASK_USER" | "NONE",
  "matched_id": integer or null
}

Rules:
- "AUTO_USE": This is when a cache entry contains the exact, specific answer to the New Query, and it is reasonably recent.
- "ASK_USER": This is when a cache entry contains the exact, specific answer to the New Query, but it might be outdated (e.g., several hours or days old).
- "NONE": This is when the cache content doesn't contain the answer to the New Query, or details are missing. If a cache entry is on the same topic but lacks the specific detail asked for, you MUST choose "NONE".
TEXT;

        $userMessage = "New Query: {$newSearchQuery}\n\nLedger:\n{$ledgerText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.2);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strpos($response, '```') !== false) {
            $response = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $response);
            $response = trim($response);
        }

        $data = json_decode($response, true);

        if (is_array($data) && isset($data['decision'])) {
            $decision = strtoupper($data['decision']);
            
            if ($decision === 'NONE') {
                return null;
            }

            $id = isset($data['matched_id']) ? (int)$data['matched_id'] : -1;

            if (isset($ledger[$id])) {
                return [
                    'decision' => $decision,
                    'cache_key' => $ledger[$id]['cache_key'],
                    'query' => $ledger[$id]['query']
                ];
            }
        }

        return null;
    }
}