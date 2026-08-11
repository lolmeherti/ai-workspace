<?php

namespace App\Services\Tools;

use App\AgentManager;
use App\Agents\ContextCondenser;
use App\Agents\SemanticCacheEvaluator;
use App\Config;
use App\Search;
use App\Scraper;
use App\Cache;

use App\Search\AskUserPolicy;
use App\Search\CacheKeyBuilder;
use App\Search\SearchCacheManager;
use App\Search\SearchPipeline;

class SearchWebTool
{
    private ContextCondenser $contextCondenser;
    private AgentManager $agent;
    private ?SemanticCacheEvaluator $cacheEvaluator;

    public function __construct(
        private \App\Database $db,
        AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist,
        ?SemanticCacheEvaluator $cacheEvaluator = null
    ) {
        $this->agent = $agent;
        $this->contextCondenser = new ContextCondenser($agent);
        $this->cacheEvaluator = $cacheEvaluator;
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        $rawQuery = $toolData['query'] ?? '';
        if (empty($rawQuery)) {
            return '[Web Search: No query provided.]';
        }

        $queries = self::splitQueries($rawQuery);
        $isSingleQuery = count($queries) === 1;
        $searchQuery = $queries[0];

        if ($isSingleQuery) {
            $ledger = Cache::getSearchLedger();
            if (!empty($ledger)) {
                // Gate 1: AskUserPolicy — age + volatility check (algorithmic, no LLM cost)
                $policy = new AskUserPolicy();
                $best = $ledger[0];

                if (!empty($best['cache_key']) && !empty($best['fetched_at'])) {
                    $entry = ['fetched_at' => $best['fetched_at']];
                    $age = time() - strtotime($best['fetched_at']);
                    $policyDecision = $policy->evaluate($entry, $best['query'] ?: $searchQuery);

                    \App\Logger::logEvent('cache_eval', 'AskUserPolicy gate', [
                        'decision' => $policyDecision,
                        'age_seconds' => $age,
                        'cached_query' => $best['query'] ?? '',
                        'current_query' => $searchQuery,
                    ], 'info', 'SearchWebTool::execute');

                    $usedCache = false;

                    if ($policyDecision !== 'NONE' && $this->cacheEvaluator !== null) {
                        // Gates 2+3: LLM two-shot verification
                        // Shot 1: title scan — is any cached entry topically relevant?
                        $candidateIdx = $this->cacheEvaluator->selectCandidate($searchQuery, $ledger);

                        if ($candidateIdx !== null) {
                            $sliced = array_slice($ledger, -5, null, true);
                            $candidate = $sliced[$candidateIdx] ?? null;

                            if ($candidate !== null && !empty($candidate['cache_key'])) {
                                $cachedContent = (new SearchCacheManager())->getEvidence($candidate['cache_key'])
                                    ?? Cache::get($candidate['cache_key'])
                                    ?? '';

                                if (!empty($cachedContent)) {
                                    // Shot 2: content verification — does it actually answer?
                                    $volatility = \App\Search\CacheTTL::estimateVolatility($candidate['query'] ?? '');
                                    $verifyDecision = $this->cacheEvaluator->verifyContent(
                                        $searchQuery,
                                        $cachedContent,
                                        $candidate['query'] ?? '',
                                        $volatility
                                    );

                                    \App\Logger::logEvent('cache_eval', 'SemanticCacheEvaluator two-shot', [
                                        'policy' => $policyDecision,
                                        'shot1_id' => $candidateIdx,
                                        'verify' => $verifyDecision,
                                        'cached_query' => $candidate['query'] ?? '',
                                        'current_query' => $searchQuery,
                                    ], 'info', 'SearchWebTool::execute');

                                    if ($verifyDecision === 'AUTO_USE') {
                                        \App\ProgressWriter::write($sessionId, 'cache_hit', 'Cached results match — serving from cache', 'amber');
                                        $emit('cache_used', []);
                                        return $cachedContent;
                                    }
                                    if ($verifyDecision === 'ASK_USER') {
                                        $emit('ask_user', [
                                            'cache_key' => $candidate['cache_key'],
                                            'query_text' => $candidate['query'] ?? $searchQuery,
                                            'session_id' => $sessionId
                                        ]);
                                        return '__ASK_USER__';
                                    }
                                    // verifyContent returned NONE — fall through to live search
                                }
                            }
                        }
                        // selectCandidate returned null — no relevant cache, fall through to live search
                    } elseif ($policyDecision === 'AUTO_USE') {
                        // No cacheEvaluator available — trust AskUserPolicy age check
                        $cacheManager = new SearchCacheManager();
                        $cachedContent = $cacheManager->getEvidence($best['cache_key'])
                            ?? Cache::get($best['cache_key'])
                            ?? '';
                        if (!empty($cachedContent)) {
                            \App\Logger::logEvent('cache_eval', 'AskUserPolicy AUTO_USE (no LLM verifier)', [
                                'cached_query' => $best['query'] ?? '',
                                'current_query' => $searchQuery,
                            ], 'info', 'SearchWebTool::execute');
                            \App\ProgressWriter::write($sessionId, 'cache_hit', 'Cached results still fresh — serving from cache', 'amber');
                            $emit('cache_used', []);
                            return $cachedContent;
                        }
                    }
                    // policyDecision ASK_USER without cacheEvaluator → skip, go live
                    // policyDecision NONE → skip, go live
                    // verifyContent returned NONE → skip, go live
                    // selectCandidate returned null → skip, go live
                }
            }
        }

        \App\Logger::logEvent('cache_eval', 'Proceeding to live search', [
            'current_query' => $searchQuery,
        ], 'info', 'SearchWebTool::execute');

        $results = [];
        $totalQueries = count($queries);
        foreach ($queries as $i => $q) {
            if ($totalQueries > 1) {
                \App\ProgressWriter::write($sessionId, 'search_querying', "Search {$i}/{$totalQueries}: {$q}", 'slate');
            }
            try {
                $results[] = $this->doLiveSearch($q, $messages, $emit, $sessionId);
            } catch (\Throwable $e) {
                \App\Logger::logEvent('search_subquery_failed', "Sub-query {$i} threw", [
                    'query' => $q,
                    'error' => $e->getMessage(),
                ], 'error', 'SearchWebTool::execute');
                \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete (partial)', 'amber');
                $results[] = "Web search for '{$q}' failed: " . $e->getMessage();
            }
        }

        if ($isSingleQuery) {
            return $results[0];
        }

        // Multi-query: combine with labelled sections
        return self::combineResults($queries, $results, 'Search');
    }

    private function doLiveSearch(string $searchQuery, array $messages, callable $emit, int $sessionId): string
    {
        $result = self::liveSearch($searchQuery, $messages, $emit, $this->contextCondenser, $sessionId);
        return $result['evidence'] ?? '';
    }

    public static function liveSearch(string $searchQuery, array $messages, callable $emit, ContextCondenser $condenser, int $sessionId = 0): array
    {
        \App\ProgressWriter::write($sessionId, 'search_querying', "Querying search engine for: {$searchQuery}", 'slate');

        $agent = new \App\AgentManager();
        $pipeline = new SearchPipeline($agent);
        $pipeline->setSessionId($sessionId);

        try {
            $result = $pipeline->run($searchQuery, $messages, $emit);
        } catch (\Throwable $e) {
            \App\Logger::logEvent('search_pipeline_failed', 'SearchPipeline threw — falling back to legacy condenser', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $searchQuery,
            ], 'error', 'SearchWebTool::liveSearch');
            return self::liveSearchLegacy($searchQuery, $messages, $emit, $condenser, $sessionId);
        }

        if (!empty($result['evidence'])) {
            $urls = $result['sourceUrls'] ?? [];
            sort($urls);
            $keySeed = CacheKeyBuilder::canonicalQuery($searchQuery) . '|' . implode('|', $urls);
            $cacheKey = 'evidence:ctx:' . hash('sha256', $keySeed);

            $cacheManager = new SearchCacheManager();
            $cacheManager->setEvidence($cacheKey, $result['evidence'], 0);
            Cache::addToLedger($searchQuery, $cacheKey);
        }

        \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete', 'emerald');

        return $result;
    }

    /**
     * Legacy condenser path — kept as fallback if SearchPipeline throws.
     * @return array{evidence: string, sourceIds: string[]}
     */
    private static function liveSearchLegacy(string $searchQuery, array $messages, callable $emit, ContextCondenser $condenser, int $sessionId): array
    {
        \App\ProgressWriter::write($sessionId, 'search_querying', "Querying search engine for: {$searchQuery}", 'slate');

        $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        $scrapedUrls = Search::query($searchQuery, $limit);

        if (empty($scrapedUrls)) {
            \App\ProgressWriter::write($sessionId, 'search_no_results', "No results for: {$searchQuery}", 'rose');
            return ['evidence' => "Web search for '{$searchQuery}' returned no results.", 'sourceIds' => []];
        }

        $perUrlTokens = self::calculateScrapeBudgetStatic($messages, count($scrapedUrls));

        $scrapedPages = [];
        foreach ($scrapedUrls as $url) {
            $shortUrl = strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url;
            \App\ProgressWriter::write($sessionId, 'scraping_start', "Scraping {$shortUrl}", 'slate');
            $pageText = Scraper::fetchAndClean($url, $perUrlTokens);
            \App\ProgressWriter::write($sessionId, 'scraping_done', "Scraped {$shortUrl}", 'emerald');
            if (!empty(trim($pageText))) {
                $scrapedPages[] = "[Source: {$url}]\n\n" . $pageText;
            }
        }

        if (empty($scrapedPages)) {
            \App\ProgressWriter::write($sessionId, 'search_no_results', "No usable content for: {$searchQuery}", 'rose');
            return ['evidence' => "Web search for '{$searchQuery}' returned no usable content.", 'sourceIds' => []];
        }

        \App\ProgressWriter::write($sessionId, 'condensing', 'Condensing search results...', 'slate');
        $condensedContext = $condenser->condense($scrapedPages, $searchQuery);

        if (!empty($condensedContext)) {
            $cacheKey = 'ctx_' . md5($searchQuery . time());
            Cache::set($cacheKey, $condensedContext);
            Cache::addToLedger($searchQuery, $cacheKey);
        }

        \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete', 'emerald');

        $evidence = $condensedContext ?: "Web search for '{$searchQuery}' completed but produced no useful summary.";
        return ['evidence' => $evidence, 'sourceIds' => []];
    }

    private function calculateScrapeBudget(array $messages, int $urlCount): int
    {
        return self::calculateScrapeBudgetStatic($messages, $urlCount);
    }

    private static function calculateScrapeBudgetStatic(array $messages, int $urlCount): int
    {
        $ctxSize = (int) Config::get('LLM_CTX_SIZE', 32768);

        $consumed = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                // Multimodal — count text + conservative image overhead
                $text = '';
                foreach ($content as $part) {
                    $text .= $part['text'] ?? '';
                }
                $consumed += (int)(mb_strlen($text) / 4) + 1000;
            } else {
                $consumed += (int)(mb_strlen((string)$content) / 4);
            }
        }

        // Reserve: response buffer (4096) + condenser overhead (1024) = 5120
        $remaining = $ctxSize - $consumed - 5120;
        if ($remaining < 10000) {
            return 2500;
        }

        // Condenser compresses ~4:1 — give it raw budget proportional to remaining room
        $rawBudget = $remaining * 4;
        $perUrl = (int)($rawBudget / $urlCount);

        return max(2500, min(15000, $perUrl));
    }

    public static function splitQueries(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function combineResults(array $queries, array $results, string $prefix): string
    {
        $combined = '';
        foreach ($queries as $i => $q) {
            if (isset($results[$i]) && $results[$i] !== '') {
                $label = chr(65 + $i);
                $combined .= "[{$prefix} {$label}: \"{$q}\"]\n{$results[$i]}\n\n";
            }
        }
        return trim($combined);
    }
}
