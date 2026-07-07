<?php

namespace App\Services\Tools;

use App\AgentManager;
use App\Agents\ContextCondenser;
use App\Config;
use App\Search;
use App\Scraper;
use App\Cache;

class SearchWebTool
{
    private ContextCondenser $contextCondenser;
    private AgentManager $agent;

    public function __construct(
        private \App\Database $db,
        AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {
        $this->agent = $agent;
        $this->contextCondenser = new ContextCondenser($agent);
    }

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {
        $searchQuery = $toolData['query'] ?? '';
        if (empty($searchQuery)) {
            return '[Web Search: No query provided.]';
        }

        $limit = (int) Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        $scrapedUrls = Search::query($searchQuery, $limit);

        if (empty($scrapedUrls)) {
            $emit('search_no_results', ['query' => $searchQuery]);
            $emit('token', ['chunk' => "[Web Search: No results found for \"{$searchQuery}\".]"]);
            return "Web search for '{$searchQuery}' returned no results.";
        }

        $perUrlTokens = $this->calculateScrapeBudget($messages, count($scrapedUrls));

        $scrapedPages = [];
        foreach ($scrapedUrls as $url) {
            $emit('scraping_start', ['url' => $url]);
            $pageText = Scraper::fetchAndClean($url, $perUrlTokens);
            $emit('scraping_done', ['url' => $url]);
            if (!empty(trim($pageText))) {
                $scrapedPages[] = "[Source: {$url}]\n\n" . $pageText;
            }
        }

        if (empty($scrapedPages)) {
            $emit('search_no_results', ['query' => $searchQuery]);
            return "Web search for '{$searchQuery}' returned no usable content.";
        }

        $emit('condensing', []);
        $condensedContext = $this->contextCondenser->condense($scrapedPages, $searchQuery);

        if (!empty($condensedContext)) {
            $cacheKey = 'ctx_' . md5($searchQuery . time());
            Cache::set($cacheKey, $condensedContext);
            Cache::addToLedger($searchQuery, $cacheKey);
        }

        return $condensedContext ?: "Web search for '{$searchQuery}' completed but produced no useful summary.";
    }

    private function calculateScrapeBudget(array $messages, int $urlCount): int
    {
        $ctxSize = (int) Config::get('LLM_CTX_SIZE', 32768);

        $consumed = 0;
        foreach ($messages as $msg) {
            $consumed += (int)(mb_strlen($msg['content'] ?? '') / 4);
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
}
