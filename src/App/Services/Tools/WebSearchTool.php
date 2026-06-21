<?php

namespace App\Services\Tools;

use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\SearchQueryRefiner;
use App\Config;
use App\Search;
use App\Scraper;
use App\Cache;

class WebSearchTool
{
    public function __construct(
        private ?SemanticCacheEvaluator $cacheEvaluator,
        private ?ContextCondenser $contextCondenser,
        private ?SearchQueryRefiner $searchQueryRefiner = null
    ) {
    }

    public function executeWebSearch(
        string $query,
        ?string $cacheAction,
        ?string $cacheKeyToUse,
        callable $emit,
        bool &$usedCache,
        array &$scrapedUrls,
        int $sessionId = 0,
        array $history = []
    ): string {
        if (!$this->cacheEvaluator || !$this->contextCondenser) {
            return '';
        }

        $isForced = false;
        if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query)) {
            $isForced = true;
        }

        if ($isForced) {
            $cacheAction = 'force_live';
        }

        $searchQuery = $query;
        if (!$isForced && $this->searchQueryRefiner) {
            $refined = $this->searchQueryRefiner->refine($query, $history);
            if ($refined === null) {
                $emit('trace', [
                    'label' => 'Web search skipped: query is conversational or refers to personal data',
                    'color' => 'amber'
                ]);
                return '';
            }
            $searchQuery = $refined;
        }

        $emit('search_decided', ['query' => $searchQuery]);

        if ($cacheAction === 'use_cache' && !empty($cacheKeyToUse)) {
            $condensedContext = Cache::get($cacheKeyToUse) ?? '';
            $usedCache = !empty($condensedContext);
            if ($usedCache) {
                $emit('cache_used', []);
            }
            return $condensedContext;
        }

        if ($cacheAction !== 'force_live') {
            $ledger = Cache::getSearchLedger();
            $evaluation = $this->cacheEvaluator->evaluate($searchQuery, $ledger);

            if ($evaluation) {
                if ($evaluation['decision'] === 'ASK_USER') {
                    $emit('ask_user', [
                        'cache_key' => $evaluation['cache_key'],
                        'query_text' => $evaluation['query'],
                        'session_id' => $sessionId
                    ]);
                    return 'ASK_USER';
                }

                if ($evaluation['decision'] === 'AUTO_USE') {
                    $condensedContext = Cache::get($evaluation['cache_key']) ?? '';
                    $usedCache = !empty($condensedContext);
                    if ($usedCache) {
                        $emit('cache_used', []);
                        return $condensedContext;
                    }
                }
            }
        }

        $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        $scrapedUrls = Search::query($searchQuery, $limit);

        if (empty($scrapedUrls)) {
            $emit('search_no_results', ['query' => $searchQuery]);
            return '';
        }

        $scrapedPages = [];
        foreach ($scrapedUrls as $url) {
            $emit('scraping_start', ['url' => $url]);
            $pageText = Scraper::fetchAndClean($url);
            $emit('scraping_done', ['url' => $url]);
            if (!empty(trim($pageText))) {
                $scrapedPages[] = "[Source: {$url}]\n\n" . $pageText;
            }
        }

        if (!empty($scrapedPages)) {
            $emit('condensing', []);
            $condensedContext = $this->contextCondenser->condense($scrapedPages, $query);

            $newCacheKey = 'ctx_' . md5($searchQuery . time());
            Cache::set($newCacheKey, $condensedContext);
            Cache::addToLedger($searchQuery, $newCacheKey);
            return $condensedContext;
        }

        $emit('search_no_results', ['query' => $searchQuery]);
        return '';
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        $query = $toolData['query'] ?? '';
        if (empty($query)) {
            return 'System error: web_search requires a query.';
        }

        $usedCache = false;
        $scrapedUrls = [];

        $result = $this->executeWebSearch(
            $query,
            null,
            null,
            $emit,
            $usedCache,
            $scrapedUrls,
            $sessionId,
            $messages
        );

        if ($result === 'ASK_USER') {
            return '';
        }

        if (empty($result)) {
            return "No relevant web results found for: {$query}";
        }

        return "[LIVE WEB SEARCH CONTEXT]:\n{$result}\n\nRespond to the user's query using the above retrieved information.";
    }
}
