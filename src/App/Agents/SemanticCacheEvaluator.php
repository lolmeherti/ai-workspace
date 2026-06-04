<?php

namespace App\Agents;

use App\AgentManager;
use App\Config;

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
            $ledgerText .= "ID: {$index} | Query: \"{$item['query']}\" | Time Executed: {$item['human_time']}\n";
        }

        $systemPrompt = <<<TEXT
Today is {$currentDate}.

Return ONLY JSON matching this schema:
{
  "decision": "AUTO_USE" | "ASK_USER" | "NONE",
  "matched_id": integer or null
}

Determine if the new search query can be satisfied by a recent past search from the ledger. 
- Set decision to "AUTO_USE" if an identical or highly similar query was run very recently.
- Set decision to "ASK_USER" if a similar query exists but is borderline (e.g., a few days old).
- Set decision to "NONE" if no past queries match or are fresh enough.
TEXT;

        $userMessage = "New Search Query: {$newSearchQuery}\n\nPast Queries Ledger:\n{$ledgerText}";

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