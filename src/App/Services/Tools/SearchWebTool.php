<?php

namespace App\Services\Tools;

use App\AgentManager;
use App\Agents\ContextCondenser;
use App\Config;
use App\Search;
use App\Scraper;

use App\Search\SearchPipeline;

class SearchWebTool
{
    /** When true, doLiveSearch returns a placeholder instead of hitting SearXNG. */
    public static bool $testMode = false;

    private array $lastSourceMap = [];

    private ContextCondenser $contextCondenser;
    private AgentManager $agent;

    public function __construct(
        private \App\Database $db,
        AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist,
    ) {
        $this->agent = $agent;
        $this->contextCondenser = new ContextCondenser($agent);
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        $this->lastSourceMap = [];

        $rawQuery = $toolData['query'] ?? '';
        if (empty($rawQuery)) {
            return '[Web Search: No query provided.]';
        }

        $queries = self::splitQueries($rawQuery);
        $totalQueries = count($queries);

        \App\Logger::logEvent('search_start', 'Proceeding to live search', [
            'current_query' => $queries[0],
        ], 'info', 'SearchWebTool::execute');

        $results = [];
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

        if ($totalQueries === 1) {
            return $results[0];
        }

        return self::combineResults($queries, $results, 'Search');
    }

    private function doLiveSearch(string $searchQuery, array $messages, callable $emit, int $sessionId): string
    {
        if (self::$testMode) {
            return '[TEST_MODE: live search disabled]';
        }
        $result = self::liveSearch($searchQuery, $messages, $emit, $this->contextCondenser, $sessionId);
        $this->lastSourceMap = array_merge($this->lastSourceMap, $result['sourceMap'] ?? []);
        return $result['evidence'] ?? '';
    }

    public function getLastSourceMap(): array
    {
        return $this->lastSourceMap;
    }

    public function resetSourceMap(): void
    {
        $this->lastSourceMap = [];
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

        \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete', 'emerald');

        return $result;
    }

    /**
     * Legacy condenser path — kept as fallback if SearchPipeline throws.
     * @return array{evidence: string, sourceIds: string[], sourceMap: array<string, array{url:string,title:string,domain:string}>}
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
        $sourceMap = [];
        $sid = 0;
        foreach ($scrapedUrls as $url) {
            $shortUrl = strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url;
            \App\ProgressWriter::write($sessionId, 'scraping_start', "Scraping {$shortUrl}", 'slate', $url);
            $pageText = Scraper::fetchAndClean($url, $perUrlTokens);
            \App\ProgressWriter::write($sessionId, 'scraping_done', "Scraped {$shortUrl}", 'emerald', $url);
            if (!empty(trim($pageText))) {
                $sid++;
                $domain = parse_url($url, PHP_URL_HOST) ?: $url;
                $scrapedPages[] = "[Source: {$domain}]\n\n" . $pageText;
                $sourceMap["S{$sid}"] = [
                    'url'    => $url,
                    'title'  => $domain,
                    'domain' => $domain,
                ];
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

        \App\ProgressWriter::write($sessionId, 'search_done', 'Search complete', 'emerald');

        $evidence = $condensedContext ?: "Web search for '{$searchQuery}' completed but produced no useful summary.";
        return ['evidence' => $evidence, 'sourceIds' => array_keys($sourceMap), 'sourceMap' => $sourceMap];
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
                $text = '';
                foreach ($content as $part) {
                    $text .= $part['text'] ?? '';
                }
                $consumed += (int)(mb_strlen($text) / 4) + 1000;
            } else {
                $consumed += (int)(mb_strlen((string)$content) / 4);
            }
        }

        $remaining = $ctxSize - $consumed - 5120;
        if ($remaining < 10000) {
            return 2500;
        }

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
