<?php

namespace App\Services\Tools;

use App\AgentManager;
use App\Agents\ContextCondenser;
use App\Agents\SemanticCacheEvaluator;
use App\Config;
use App\Search;
use App\Scraper;
use App\Cache;

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
            if ($this->cacheEvaluator !== null && !empty($ledger)) {
                \App\ProgressWriter::write($sessionId, 'cache_check', 'Checking semantic cache...', 'slate');
                $evaluation = $this->cacheEvaluator->evaluate($searchQuery, $ledger);
                if ($evaluation !== null) {
                    if ($evaluation['decision'] === 'AUTO_USE') {
                        $cachedContent = Cache::get($evaluation['cache_key']) ?? '';
                        if (!empty($cachedContent)) {
                            \App\ProgressWriter::write($sessionId, 'cache_hit', 'Memory cache matched — serving cached results', 'amber');
                            $emit('cache_used', []);
                            return $cachedContent;
                        }
                    }
                    if ($evaluation['decision'] === 'ASK_USER') {
                        $emit('ask_user', [
                            'cache_key' => $evaluation['cache_key'],
                            'query_text' => $evaluation['query'],
                            'session_id' => $sessionId
                        ]);
                        return '__ASK_USER__';
                    }
                }
            }
        }

        $results = [];
        $totalQueries = count($queries);
        foreach ($queries as $i => $q) {
            if ($totalQueries > 1) {
                \App\ProgressWriter::write($sessionId, 'search_querying', "Search {$i}/{$totalQueries}: {$q}", 'slate');
            }
            $results[] = $this->doLiveSearch($q, $messages, $emit, $sessionId);
        }

        if ($isSingleQuery) {
            return $results[0];
        }

        // Multi-query: combine with labelled sections
        return self::combineResults($queries, $results, 'Search');
    }

    private function doLiveSearch(string $searchQuery, array $messages, callable $emit, int $sessionId): string
    {
        return self::liveSearch($searchQuery, $messages, $emit, $this->contextCondenser, $sessionId);
    }

    public static function liveSearch(string $searchQuery, array $messages, callable $emit, ContextCondenser $condenser, int $sessionId = 0): string
    {
        \App\ProgressWriter::write($sessionId, 'search_querying', "Querying search engine for: {$searchQuery}", 'slate');

        $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        $scrapedUrls = Search::query($searchQuery, $limit);

        if (empty($scrapedUrls)) {
            \App\ProgressWriter::write($sessionId, 'search_no_results', "No results for: {$searchQuery}", 'rose');
            return "Web search for '{$searchQuery}' returned no results.";
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
            return "Web search for '{$searchQuery}' returned no usable content.";
        }

        \App\ProgressWriter::write($sessionId, 'condensing', 'Condensing search results...', 'slate');
        $condensedContext = $condenser->condense($scrapedPages, $searchQuery);

        if (!empty($condensedContext)) {
            $cacheKey = 'ctx_' . md5($searchQuery . time());
            Cache::set($cacheKey, $condensedContext);
            Cache::addToLedger($searchQuery, $cacheKey);
        }

        return $condensedContext ?: "Web search for '{$searchQuery}' completed but produced no useful summary.";
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
