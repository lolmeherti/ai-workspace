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

        $scrapedPages = [];
        foreach ($scrapedUrls as $url) {
            $emit('scraping_start', ['url' => $url]);
            $pageText = Scraper::fetchAndClean($url);
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
}
