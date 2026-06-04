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

        $systemPrompt = "You are a highly intelligent cache-routing agent. The current date and time is {$currentDate}.\nYour job is to prevent unnecessary live web searches by evaluating if a new search query can be satisfied by a recent past search.\n\nYou will be provided with the user's new search query, followed by a list of past queries and the exact time they were executed.\n\nCRITICAL INSTRUCTIONS:\n1. If a similar query was executed very recently and results are definitely identical, reply EXACTLY with AUTO_USE:[ID].\n2. If a similar query exists but it is borderline, reply EXACTLY with ASK_USER:[ID].\n3. If no past query is relevant, or if the relevant cache is clearly too old, reply EXACTLY with NONE.\n\nDo not include any other text.";

        $userMessage = "New Search Query: {$newSearchQuery}\n\nPast Queries Ledger:\n{$ledgerText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.2);
        
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strtoupper($response) === 'NONE') {
            return null;
        }

        if (preg_match('/^(AUTO_USE|ASK_USER):\s*(\d+)$/i', $response, $matches)) {
            $decision = strtoupper($matches[1]);
            $id = (int) $matches[2];

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