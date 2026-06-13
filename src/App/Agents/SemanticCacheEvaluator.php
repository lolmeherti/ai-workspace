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
        
        $slicedLedger = array_slice($ledger, -5, null, true);
        $ledgerText = "";
        foreach ($slicedLedger as $index => $item) {
            $rawContent = Cache::get($item['cache_key']) ?? '';
            $ledgerText .= "ID: {$index}\nQuery: \"{$item['query']}\"\nCache:\n\"{$rawContent}\"\n";
            $ledgerText .= "--------------------------------------------------\n";
        }

        $systemPrompt = <<<TEXT
Today is {$currentDate}.

You are a strict Cache Verification Agent. Your job is to analyze if any existing cached context in the Ledger contains the exact, highly specific data required to answer the New Query.

Decision Guidelines:
- "AUTO_USE": Choose this ONLY if the cached context text actually contains the specific facts, numbers, lists, or entities required to fully answer the New Query. It is not enough for the cache query to be on the same topic.
- "ASK_USER": Choose this if the cached context contains the exact facts, but the information is time-sensitive (e.g., news, sports standings, real-time status) and may need confirmation if it is more than a few hours old.
- "NONE": Choose this if:
  1. The cached text does NOT contain the specific details asked for.
  2. The user is asking for a complete list or full details (e.g. "full standings", "complete details", "all items") but the cached text only contains a summary, a single item, or partial data.
  3. No ledger entries are relevant.

CRITICAL WARNING: Avoid "Topic Matching". For example, if the New Query is "what are the full standings" and a cached entry is "who is leading the championship", do NOT choose AUTO_USE. The cached text only has the leader, not the full standings. In such cases of incomplete details, you MUST choose "NONE".

Format your output STRICTLY as a JSON object matching this schema:
{
  "decision": "AUTO_USE" | "ASK_USER" | "NONE",
  "matched_id": integer or null
}
TEXT;

        $userMessage = "New Query: {$newSearchQuery}\n\nLedger:\n{$ledgerText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        $data = \App\JsonParser::extractAndDecode($response);

        if (is_array($data) && isset($data['decision'])) {
            $decision = strtoupper($data['decision']);
            
            if ($decision === 'NONE') {
                return null;
            }

            $id = isset($data['matched_id']) ? (int)$data['matched_id'] : -1;

            if (isset($slicedLedger[$id])) {
                return [
                    'decision' => $decision,
                    'cache_key' => $slicedLedger[$id]['cache_key'],
                    'query' => $slicedLedger[$id]['query']
                ];
            }
        }

        return null;
    }
}