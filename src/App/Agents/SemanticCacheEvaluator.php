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

    /**
     * Shot 1 — title scan. Show the LLM cached query titles with ages
     * and volatility info. It picks which entries are worth checking.
     * Returns the ledger index of the best candidate, or null if none relevant.
     *
     * @param string $newQuery The user's current search query
     * @param array $ledger The full search ledger
     * @return int|null Ledger index of selected candidate, or null
     */
    public function selectCandidate(string $newQuery, array $ledger): ?int
    {
        if (empty($ledger)) {
            return null;
        }

        $currentDate = date('l, F j, Y g:i A');
        $sliced = array_slice($ledger, -5, null, true);

        $lines = [];
        foreach ($sliced as $index => $item) {
            $age = isset($item['fetched_at']) ? (time() - strtotime($item['fetched_at'])) : null;
            $ageStr = $age !== null ? $this->formatAge($age) : 'unknown';
            $volatility = \App\Search\CacheTTL::estimateVolatility($item['query'] ?? '');
            $lines[] = "ID {$index}: \"{$item['query']}\" — {$ageStr} old, volatility: {$volatility}";
        }

        $list = implode("\n", $lines);

        $system = <<<TEXT
Today is {$currentDate}.

You are a cache relevance filter. Below is a list of past search queries with their ages and topic volatility. The user has a new query.

For each past query, consider:
- Is it about the same topic as the new query?
- For high-volatility topics (sports, news, stocks, weather), cached data older than 15 minutes is likely stale.
- For low-volatility topics (documentation, specs, definitions), cached data can remain valid for hours or days.

Return a JSON object:
{"decision": "CHECK", "id": <index>} — if one cached query is worth checking
{"decision": "NONE"} — if no cached query is relevant

Only pick ONE. Do not explain. Output only the JSON.
TEXT;

        $user = "New query: {$newQuery}\n\nPast searches:\n{$list}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));
        $data = \App\JsonParser::extractAndDecode($response);

        if (!is_array($data) || ($data['decision'] ?? '') !== 'CHECK') {
            return null;
        }

        $id = isset($data['id']) ? (int) $data['id'] : -1;
        if (!isset($sliced[$id])) {
            return null;
        }

        return $id;
    }

    /**
     * Shot 2 — content verification. The LLM sees the actual cached
     * evidence and decides whether it contains the specific data the
     * user is asking for.
     *
     * @return string 'AUTO_USE', 'ASK_USER', or 'NONE'
     */
    public function verifyContent(string $query, string $cachedContent, string $cachedQueryTitle, string $volatility): string
    {
        $system = <<<TEXT
You are a strict cache verifier. You are given:
1. The user's new query
2. A cached search result from a past query on a similar topic
3. The original query that produced the cache
4. The topic's volatility (how quickly this data changes)

Check whether the cached content actually contains the specific facts, data, or
details the user is asking for.

Rules:
- If the cached content has the exact data the user wants → "AUTO_USE"
- If the cached content has the data but the topic is high-volatility and the
  cache is hours old → "ASK_USER"
- If the cached content does NOT contain the specific details (e.g. user wants
  full standings but cache only has one team's position, or user wants numbers
  but cache only has source names) → "NONE"
- If the cached content is snippets/titles without actual data → "NONE"

Return ONLY a JSON object: {"decision": "AUTO_USE"|"ASK_USER"|"NONE"}
TEXT;

        $user = "New query: {$query}\nOriginal cached query: {$cachedQueryTitle}\nVolatility: {$volatility}\n\nCached content:\n{$cachedContent}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));
        $data = \App\JsonParser::extractAndDecode($response);

        if (is_array($data) && isset($data['decision'])) {
            $decision = strtoupper($data['decision']);
            if (in_array($decision, ['AUTO_USE', 'ASK_USER', 'NONE'], true)) {
                return $decision;
            }
        }

        return 'NONE';
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }
}
