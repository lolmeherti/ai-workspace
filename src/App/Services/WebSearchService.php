<?php

namespace App\Services;

use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Cache;

class WebSearchService
{
    private ?SearchDecider $searchDecider;
    private ?SemanticCacheEvaluator $cacheEvaluator;
    private ?ContextCondenser $contextCondenser;

    public function __construct(
        ?SearchDecider $searchDecider,
        ?SemanticCacheEvaluator $cacheEvaluator,
        ?ContextCondenser $contextCondenser
    ) {
        $this->searchDecider = $searchDecider;
        $this->cacheEvaluator = $cacheEvaluator;
        $this->contextCondenser = $contextCondenser;
    }

    public function executeDecision(
        string $query, 
        array $history, 
        ?string $cacheAction, 
        ?string $cacheKeyToUse, 
        callable $emit, 
        bool &$usedCache, 
        array &$scrapedUrls, 
        ?string &$searchQuery
    ): string {
        if (!$this->searchDecider || !$this->cacheEvaluator || !$this->contextCondenser) {
            return '';
        }

        $isForced = false;
        if (preg_match('/force\s+(?:the\s+)?(?:web\s+)?search/i', $query)) {
            $isForced = true;
        }

        if ($isForced) {
            $cacheAction = 'force_live';
        }

        // Only call search decider if searchQuery wasn't already resolved externally
        if ($searchQuery === null) {
            $searchQuery = $this->searchDecider->requiresSearch($query, $history);
        }
        if (!$searchQuery) {
            return '';
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
                        'session_id' => $history[0]['session_id'] ?? 0
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

        $result = \App\Services\Tools\SearchWebTool::liveSearch($searchQuery, [], $emit, $this->contextCondenser, 0);
        if (!empty($result['evidence']) && !str_starts_with($result['evidence'], 'Web search for')) {
            $scrapedUrls = []; // SearchPipeline handles fetching internally
            return $result['evidence'];
        }

        $emit('search_no_results', ['query' => $searchQuery]);
        return '';
    }
}