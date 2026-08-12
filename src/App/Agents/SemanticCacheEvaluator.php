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
     * Single-shot semantic cache evaluation over a set of candidates
     * with pre-resolved content. Candidates are keyed by cache_key so
     * the caller never depends on positional indices.
     *
     * @param string $newQuery   The user's current search query
     * @param array  $candidates Map of cache_key => ['query', 'fetched_at', 'content']
     * @return array ['decision' => 'USE'|'NONE', 'cache_key' => string|null]
     */
    public function evaluateCandidates(string $newQuery, array $candidates): array
    {
        if (empty($candidates)) {
            return ['decision' => 'NONE', 'cache_key' => null];
        }

        $currentDate = date('l, F j, Y g:i A');

        // Build numeric ID → cache_key mapping for the LLM prompt
        $idMap = array_keys($candidates);
        $lines = [];
        foreach ($idMap as $i => $cacheKey) {
            $c = $candidates[$cacheKey];
            $age = isset($c['fetched_at']) ? (time() - strtotime($c['fetched_at'])) : null;
            $ageStr = $age !== null ? $this->formatAge($age) : 'unknown';
            $volatility = \App\Search\CacheTTL::estimateVolatility($c['query'] ?? '');
            $preview = $this->buildContentPreview($c['content'] ?? '');
            $lines[] = "ID {$i}: \"{$c['query']}\" — {$ageStr} old, volatility: {$volatility}\n  Content: {$preview}";
        }

        $list = implode("\n\n", $lines);

        $system = <<<TEXT
Today is {$currentDate}.

You are evaluating whether any cached search result can answer a new user query.

For each candidate, consider:
- Is it about the same topic as the new query?
- Does the content preview contain information that substantially answers the request?
- For high-volatility topics (sports, news, stocks, weather), even relevant content may be stale.
- For low-volatility topics (documentation, specs, definitions), older content may still be valid.

Cached entries:

{$list}

Decide:
- USE <id> — the cached entry can substantially answer the new query
- NONE — no cached entry is relevant

Return ONLY: {"decision": "USE", "id": <N>} or {"decision": "NONE"}
TEXT;

        $user = "New query: {$newQuery}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $temperature = (float) Config::get('AGENT_CACHE_EVAL_TEMP', 0.1);
        $response = trim($this->agent->chat($messages, false, null, $temperature));
        $data = \App\JsonParser::extractAndDecode($response);

        if (!is_array($data)) {
            \App\Logger::logEvent('cache_eval', 'semantic_parse_failure', [
                'response_preview' => mb_substr($response, 0, 300),
                'candidate_count' => count($candidates),
                'current_query' => $newQuery,
            ], 'warn', 'SemanticCacheEvaluator::evaluateCandidates');
            return ['decision' => 'NONE', 'cache_key' => null];
        }

        $decision = strtoupper($data['decision'] ?? 'NONE');

        if ($decision === 'USE' && isset($data['id'])) {
            $id = (int) $data['id'];
            if (isset($idMap[$id])) {
                return ['decision' => 'USE', 'cache_key' => $idMap[$id]];
            }
            \App\Logger::logEvent('cache_eval', 'semantic_use_invalid_id', [
                'returned_id' => $id,
                'max_candidates' => count($candidates),
                'current_query' => $newQuery,
            ], 'warn', 'SemanticCacheEvaluator::evaluateCandidates');
            return ['decision' => 'NONE', 'cache_key' => null];
        }

        return ['decision' => 'NONE', 'cache_key' => null];
    }

    /**
     * Build a content preview for the LLM evaluator. Extracts text from
     * <chunk> elements in XML evidence, showing the first ~200 chars of up
     * to 4 chunks — preserving breadth across the evidence instead of
     * head-truncating. Falls back to first ~800 chars of plain text.
     */
    private function buildContentPreview(string $evidenceText): string
    {
        if (preg_match('/<chunk/', $evidenceText)) {
            preg_match_all('/<chunk[^>]*>(.*?)<\/chunk>/s', $evidenceText, $matches);
            if (!empty($matches[1])) {
                $previews = [];
                $maxChunks = min(4, count($matches[1]));
                for ($i = 0; $i < $maxChunks; $i++) {
                    $text = strip_tags($matches[1][$i]);
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    if ($text === '') continue;
                    if (mb_strlen($text) > 200) {
                        $text = mb_substr($text, 0, 197) . '...';
                    }
                    $previews[] = "[Chunk " . ($i + 1) . "] {$text}";
                }
                if (!empty($previews)) {
                    return implode("\n", $previews);
                }
            }
        }

        $text = strip_tags($evidenceText);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) > 800) {
            $text = mb_substr($text, 0, 797) . '...';
        }
        return $text;
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }
}
