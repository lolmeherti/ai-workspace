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
    /** When true, doLiveSearch returns a placeholder instead of hitting SearXNG. */
    public static bool $testMode = false;

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
                $canonicalNew = \App\Search\CacheKeyBuilder::canonicalQuery($searchQuery);

                // ── Step 1: Lexical lookup — exact canonical match ──
                $lexicalCandidate = null;
                foreach ($ledger as $entry) {
                    if (!empty($entry['cache_key']) && !empty($entry['fetched_at'])) {
                        if ($canonicalNew === \App\Search\CacheKeyBuilder::canonicalQuery($entry['query'] ?? '')) {
                            $lexicalCandidate = $entry;
                            break;
                        }
                    }
                }

                if ($lexicalCandidate !== null) {
                    $cachedContent = (new SearchCacheManager())->getEvidence($lexicalCandidate['cache_key'])
                        ?? Cache::get($lexicalCandidate['cache_key'])
                        ?? '';

                    if (!empty($cachedContent)) {
                        \App\Logger::logEvent('cache_eval', 'lexical_hit', [
                            'cached_query' => $lexicalCandidate['query'] ?? '',
                            'current_query' => $searchQuery,
                            'canonical' => $canonicalNew,
                        ], 'info', 'SearchWebTool::execute');

                        $policy = new AskUserPolicy();
                        $policyDecision = $policy->evaluate(
                            ['fetched_at' => $lexicalCandidate['fetched_at']],
                            $searchQuery
                        );

                        \App\Logger::logEvent('cache_eval', 'AskUserPolicy after lexical match', [
                            'decision' => $policyDecision,
                            'cached_query' => $lexicalCandidate['query'] ?? '',
                            'current_query' => $searchQuery,
                        ], 'info', 'SearchWebTool::execute');

                        if ($policyDecision === 'AUTO_USE') {
                            \App\ProgressWriter::write($sessionId, 'cache_hit', 'Cached results match — serving from cache', 'amber');
                            $emit('cache_used', []);
                            return $cachedContent;
                        }
                        if ($policyDecision === 'ASK_USER') {
                            \App\Logger::logEvent('cache_eval', 'ASK_USER — returning sentinel with metadata', [
                                'cache_key' => $lexicalCandidate['cache_key'] ?? '(null)',
                                'query_text' => $lexicalCandidate['query'] ?? $searchQuery,
                            ], 'info', 'SearchWebTool::execute');
                            return '__ASK_USER__:' . json_encode([
                                'cache_key'  => $lexicalCandidate['cache_key'] ?? '',
                                'query_text' => $lexicalCandidate['query'] ?? $searchQuery,
                            ]);
                        }

                        \App\Logger::logEvent('cache_eval', 'policy_none', [
                            'decision' => $policyDecision,
                            'cached_query' => $lexicalCandidate['query'] ?? '',
                            'current_query' => $searchQuery,
                        ], 'info', 'SearchWebTool::execute');
                    } else {
                        \App\Logger::logEvent('cache_eval', 'lexical_hit_empty_cache', [
                            'cache_key' => $lexicalCandidate['cache_key'] ?? '(null)',
                            'cached_query' => $lexicalCandidate['query'] ?? '',
                            'current_query' => $searchQuery,
                        ], 'warn', 'SearchWebTool::execute');
                    }
                } else {
                    \App\Logger::logEvent('cache_eval', 'lexical_miss', [
                        'canonical' => $canonicalNew,
                        'ledger_count' => count($ledger),
                        'current_query' => $searchQuery,
                    ], 'info', 'SearchWebTool::execute');

                    // ── Step 2: Semantic candidate selection ──
                    if ($this->cacheEvaluator !== null) {
                        $sliced = array_slice($ledger, -5, null, true);
                        $candidates = [];

                        foreach ($sliced as $entry) {
                            if (!empty($entry['cache_key']) && !empty($entry['fetched_at'])) {
                                $content = (new SearchCacheManager())->getEvidence($entry['cache_key'])
                                    ?? Cache::get($entry['cache_key'])
                                    ?? '';
                                if (!empty($content)) {
                                    $candidates[$entry['cache_key']] = [
                                        'query' => $entry['query'] ?? '',
                                        'fetched_at' => $entry['fetched_at'],
                                        'cache_key' => $entry['cache_key'],
                                        'content' => $content,
                                    ];
                                }
                            }
                        }

                        if (!empty($candidates)) {
                            $evalResult = $this->cacheEvaluator->evaluateCandidates($searchQuery, $candidates);

                            if (($evalResult['decision'] ?? '') === 'USE') {
                                $selectedCacheKey = $evalResult['cache_key'] ?? '';
                                $selected = $candidates[$selectedCacheKey] ?? null;

                                if ($selected !== null) {
                                    \App\Logger::logEvent('cache_eval', 'semantic_use', [
                                        'cache_key' => $selectedCacheKey,
                                        'cached_query' => $selected['query'] ?? '',
                                        'current_query' => $searchQuery,
                                        'candidate_count' => count($candidates),
                                    ], 'info', 'SearchWebTool::execute');

                                    $policy = new AskUserPolicy();
                                    $policyDecision = $policy->evaluate(
                                        ['fetched_at' => $selected['fetched_at']],
                                        $searchQuery
                                    );

                                    \App\Logger::logEvent('cache_eval', 'AskUserPolicy after semantic match', [
                                        'decision' => $policyDecision,
                                        'cached_query' => $selected['query'] ?? '',
                                        'current_query' => $searchQuery,
                                    ], 'info', 'SearchWebTool::execute');

                                    if ($policyDecision === 'AUTO_USE') {
                                        $content = (new SearchCacheManager())->getEvidence($selected['cache_key'])
                                            ?? Cache::get($selected['cache_key'])
                                            ?? '';
                                        \App\ProgressWriter::write($sessionId, 'cache_hit', 'Cached results match — serving from cache', 'amber');
                                        $emit('cache_used', []);
                                        return $content;
                                    }
                                    if ($policyDecision === 'ASK_USER') {
                                        \App\Logger::logEvent('cache_eval', 'ASK_USER — returning sentinel with metadata', [
                                            'cache_key' => $selected['cache_key'] ?? '(null)',
                                            'query_text' => $selected['query'] ?? $searchQuery,
                                        ], 'info', 'SearchWebTool::execute');
                                        return '__ASK_USER__:' . json_encode([
                                            'cache_key'  => $selected['cache_key'] ?? '',
                                            'query_text' => $selected['query'] ?? $searchQuery,
                                        ]);
                                    }

                                    \App\Logger::logEvent('cache_eval', 'policy_none', [
                                        'decision' => $policyDecision,
                                        'cached_query' => $selected['query'] ?? '',
                                        'current_query' => $searchQuery,
                                    ], 'info', 'SearchWebTool::execute');
                                }
                            } else {
                                \App\Logger::logEvent('cache_eval', 'semantic_none', [
                                    'current_query' => $searchQuery,
                                    'candidate_count' => count($candidates),
                                ], 'info', 'SearchWebTool::execute');
                            }
                        } else {
                            \App\Logger::logEvent('cache_eval', 'no_viable_candidates', [
                                'canonical' => $canonicalNew,
                                'ledger_count' => count($ledger),
                                'current_query' => $searchQuery,
                            ], 'info', 'SearchWebTool::execute');
                        }
                    }
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
        if (self::$testMode) {
            return '[TEST_MODE: live search disabled]';
        }
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
            \App\Logger::logEvent('search_pipeline_ok', 'SearchPipeline succeeded', [
                'query' => $searchQuery,
                'evidence_len' => strlen($result['evidence'] ?? ''),
                'source_count' => count($result['sourceIds'] ?? []),
            ], 'info', 'SearchWebTool::liveSearch');
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

        \App\Logger::logEvent('search_urls_found', 'SearXNG returned URLs', [
            'query' => $searchQuery,
            'url_count' => count($scrapedUrls),
            'urls' => $scrapedUrls,
        ], 'info', 'SearchWebTool::liveSearchLegacy');

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

        \App\Logger::logEvent('search_condensed', 'Context condenser produced output', [
            'query' => $searchQuery,
            'scraped_page_count' => count($scrapedPages),
            'condensed_len' => strlen($condensedContext),
            'condensed_preview' => mb_substr($condensedContext, 0, 200),
        ], 'info', 'SearchWebTool::liveSearchLegacy');

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
