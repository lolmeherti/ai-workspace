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

    public function evaluate(string $newSearchQuery, array $ledger): ?string
    {
        if (empty($ledger)) {
            return null;
        }

        $currentDate = date('l, F j, Y g:i A');
        
        $ledgerText = "";
        foreach ($ledger as $index => $item) {
            $ledgerText .= "ID: {$index} | Query: \"{$item['query']}\" | Time Executed: {$item['human_time']}\n";
        }

        $systemPrompt = "You are a highly intelligent cache-routing agent. The current date and time is {$currentDate}.\nYour job is to prevent unnecessary live web searches by evaluating if a new search query can be satisfied by a recent past search.\n\nYou will be provided with the user's new search query, followed by a list of past queries and the exact time they were executed.\n\nCRITICAL INSTRUCTIONS:\n1. Pay intense attention to the time elapsed.\n2. If a similar query was executed very recently (e.g., today or a few hours ago), the results will be identical. You MUST prefer the cached query.\n3. If the query is about fast-moving live news/stocks and the cache is several days old, the cache is outdated.\n4. If the query is about a static topic and the cache is from 6 days ago, the cache is still valid.\n\nIf a past query is semantically similar and the cache is still fresh enough for the topic, reply ONLY with the exact numeric ID of the past query. If no past query is relevant, or if the relevant cache is too old, reply EXACTLY with the word: NONE.";

        $userMessage = "New Search Query: {$newSearchQuery}\n\nPast Queries Ledger:\n{$ledgerText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.2);
        
        $response = trim($this->agent->chat($messages, false, null, $temperature));

        if (strtoupper($response) === 'NONE' || !is_numeric($response)) {
            return null;
        }

        $id = (int) $response;

        if (isset($ledger[$id])) {
            return $ledger[$id]['cache_key'];
        }

        return null;
    }
}