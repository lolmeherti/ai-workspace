<?php

namespace App\Search;

use App\AgentManager;
use App\Config;

final class SearchPipeline
{
    private ContentExtractor $extractor;
    private StructuralChunker $chunker;
    private Bm25Retriever $retriever;
    private EvidenceBuilder $builder;
    private TokenCounter $counter;
    private SourceCondenser $sourceCondenser;
    private RetrievalPolicy $policy;
    private AgentManager $agent;
    private int $sessionId = 0;
    private int $sourceStartSeq = 1;
    private ContentDeduplicator $contentDeduplicator;

    public function __construct(AgentManager $agent)
    {
        $this->agent = $agent;
        $this->extractor = new ContentExtractor();
        $this->chunker = new StructuralChunker();
        $this->retriever = new Bm25Retriever();
        $this->builder = new EvidenceBuilder();
        $this->counter = new TokenCounter();
        $this->sourceCondenser = new SourceCondenser($agent);
        $this->policy = RetrievalPolicy::default();
        $this->contentDeduplicator = new ContentDeduplicator();
    }

    public function setSessionId(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function setSourceStartSeq(int $startSeq): void
    {
        $this->sourceStartSeq = $startSeq;
    }

    /**
     * Run the full search pipeline. Single implementation: browser bridge.
     * Bridge failure is surfaced explicitly rather than degrading to snippets.
     *
     * @return array{evidence: string, sourceIds: string[], sourceMap: array<string, array{url:string,title:string,domain:string}>, backingChunks: \App\Search\WebChunk[], selectedChunks: \App\Search\WebChunk[]}
     */
    public function run(string $query, array $messages, callable $emit): array
    {
        $bridge = new BridgeFetcher();

        if (!$bridge->isConnected()) {
            $this->emitProgress('search_unavailable', 'Web search unavailable — browser bridge not connected', $emit);
            \App\Logger::logEvent('bridge_unavailable', 'Bridge disconnected — returning explicit unavailable result', [
                'query' => $query,
            ], 'warn', 'SearchPipeline::run');
            return self::emptyResult('Web search is unavailable: the browser bridge is not connected (Edge extension or relay is offline).');
        }

        return $this->runBridgeMode($query, $messages, $emit, $bridge);
    }

    // ── Bridge mode (browser SERP + full extraction) ────────────────────

    private function runBridgeMode(string $query, array $messages, callable $emit, BridgeFetcher $bridge): array
    {
        $this->emitProgress('search_querying', "Querying browser search for: {$query}", $emit);

        $candidates = $bridge->searchSERP($query);
        \App\Logger::logEvent('bridge_serp', 'Bridge SERP result', [
            'query' => $query,
            'raw_count' => count($candidates),
        ], 'info', 'SearchPipeline::runBridgeMode');
        if (empty($candidates)) {
            $this->emitProgress('search_no_results', 'No browser results for this query', $emit);
            \App\Logger::logEvent('search_no_results', 'Bridge SERP returned no candidates', [
                'query' => $query,
            ], 'warn', 'SearchPipeline::runBridgeMode');
            return self::emptyResult('No web search results were returned for this query.');
        }

        $deduplicator = new CandidateDeduplicator();
        $candidates = $deduplicator->deduplicate($candidates);
        $candidates = CandidateRanker::deprioritize($candidates);

        // ── Sequential fetch via bridge ──────────────────────────────
        $limit = (int) \App\Config::get('MAX_SEARCH_RESULTS_TO_SCRAPE', 3);
        if (count($candidates) > $limit) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $minSources = (int) \App\Config::get('MIN_EVIDENCE_SOURCES', 2);

        $sourceId = $this->sourceStartSeq - 1;
        $allChunks = [];
        $fetchedUrls = [];

        foreach ($candidates as $candidate) {
            $sourceId++;
            $sid = "S{$sourceId}";

            $shortUrl = strlen($candidate->url) > 60
                ? substr($candidate->url, 0, 57) . '...'
                : $candidate->url;

            $this->emitProgress('scraping_start', "Scraping {$shortUrl}", $emit, $candidate->url);

            $result = $bridge->fetch($candidate->url);

            $totalBodyLen = 0;
            $entityCount = 0;
            if ($result->isSuccess() && $result->content) {
                foreach ($result->content['entities'] ?? [] as $e) {
                    $totalBodyLen += strlen($e['body'] ?? '');
                }
                $entityCount = count($result->content['entities'] ?? []);
            }
            BridgeFetchLogger::record($candidate->url, $result->status, $totalBodyLen, $entityCount);

            if (!$result->isSuccess()) {
                $this->emitProgress('scraping_done', "Scraped {$shortUrl} ({$result->status})", $emit, $candidate->url);
                continue;
            }

            $this->emitProgress('scraping_done', "Scraped {$shortUrl}", $emit, $candidate->url);

            $chunks = $this->chunksFromBridgeContent($result->content, $sid, $candidate->url);
            if (empty($chunks)) {
                continue;
            }

            $sourceBody = '';
            foreach ($chunks as $chunk) {
                $sourceBody .= $chunk->text . "\n";
            }

            $decision = $this->contentDeduplicator->evaluate($sourceBody);

            \App\Logger::logEvent('bridge_dedup', $decision['duplicate'] ? 'Near-duplicate source dropped' : 'Source kept', [
                'url' => $candidate->url,
                'source_id' => $sid,
                'decision' => $decision['duplicate'] ? 'drop' : 'keep',
                'max_similarity' => round($decision['max_similarity'], 4),
                'matched_source' => $decision['matched_label'],
                'threshold' => ContentDeduplicator::SIMILARITY_THRESHOLD,
            ], 'info', 'SearchPipeline::runBridgeMode');

            if ($decision['duplicate']) {
                continue;
            }
            $this->contentDeduplicator->keep($sourceBody, "{$sid} {$candidate->url}");

            $fetchedUrls[] = $candidate->url;
            $allChunks = array_merge($allChunks, $chunks);

            // ── Incremental stop decision (raw BM25, no diversity) ────
            $ranked = $this->retriever->rankRaw($allChunks, $query, $query);
            $topN = min(5, count($ranked));
            $topRankedSourceIds = [];
            $bestRanks = [];
            for ($i = 0; $i < $topN; $i++) {
                $rsid = $ranked[$i]->sourceId;
                $topRankedSourceIds[$rsid] = true;
                if (!isset($bestRanks[$rsid])) {
                    $bestRanks[$rsid] = $i + 1;
                }
            }
            $topSourceCount = count($topRankedSourceIds);
            $isSimple = $this->queryIsSimple($query);

            \App\Logger::logEvent('bridge_stop_check', 'Incremental stop evaluation', [
                'url_index' => $sourceId,
                'top_n' => $topN,
                'top_ranked_source_ids' => array_keys($topRankedSourceIds),
                'best_raw_ranks' => $bestRanks,
                'top_source_count' => $topSourceCount,
                'min_required' => $minSources,
                'query_complexity' => $isSimple ? 'simple' : 'complex',
                'fetched_so_far' => count($fetchedUrls),
            ], 'info', 'SearchPipeline::runBridgeMode');

            if ($topSourceCount >= $minSources && $isSimple) {
                $this->emitProgress('search_coverage', 'Evidence sufficient — stopping early', $emit);
                \App\Logger::logEvent('bridge_stop', 'Early stop — enough distinct sources in raw top-N', [
                    'reason' => 'distinct_sources_in_top_ranks',
                    'source_count' => $topSourceCount,
                    'min_required' => $minSources,
                    'best_ranks' => $bestRanks,
                ], 'info', 'SearchPipeline::runBridgeMode');
                break;
            }
        }

        if (empty($allChunks)) {
            $this->emitProgress('search_no_results', 'No usable page content was extracted', $emit);
            \App\Logger::logEvent('search_no_results', 'All bridge fetches failed or yielded no content', [
                'query' => $query,
                'candidates_attempted' => count($candidates),
            ], 'warn', 'SearchPipeline::runBridgeMode');
            return self::emptyResult('Web pages could not be fetched for this query.');
        }

        // ── Final BM25 retrieval across all fetched chunks ───────────
        $this->emitProgress('search_retrieving', 'Selecting relevant passages...', $emit);
        $selected = $this->retriever->rank($allChunks, $query, $query, $this->policy);

        if (empty($selected)) {
            $this->emitProgress('search_no_results', 'No relevant content found in fetched pages', $emit);
            \App\Logger::logEvent('search_no_results', 'All bridge chunks scored zero or were filtered', [
                'query' => $query,
                'total_chunks_extracted' => count($allChunks),
            ], 'warn', 'SearchPipeline::runBridgeMode');
            return self::emptyResult('No relevant content was found in the fetched pages.');
        }

        // ── Three-level evidence fitting ────────────────────────────
        $this->emitProgress('search_condensing', 'Fitting evidence to budget...', $emit);
        $fit = $this->threeLevelFit($selected, $query, $messages);

        $sourceIds = [];
        foreach ($allChunks as $chunk) {
            $sourceIds[] = $chunk->sourceId;
        }
        $sourceIds = array_values(array_unique($sourceIds));

        $sourceMap = [];
        foreach ($allChunks as $chunk) {
            if (isset($sourceMap[$chunk->sourceId])) continue;
            $sourceMap[$chunk->sourceId] = [
                'url'    => $chunk->finalUrl ?: $chunk->url,
                'title'  => $chunk->title,
                'domain' => $chunk->domain,
            ];
        }

        \App\Logger::logEvent('bridge_evidence', 'Bridge evidence fitting complete', [
            'query' => $query,
            'total_chunks' => count($allChunks),
            'selected_chunks' => count($selected),
            'evidence_len' => strlen($fit['evidence']),
            'source_count' => count($sourceIds),
        ], 'info', 'SearchPipeline::runBridgeMode');

        // Atomic extraction has MOVED OUT of SearchPipeline: it now runs as a
        // deferred consolidation in ChatManager (start of a later turn, gated by
        // backlog/headroom policy) over the selected chunks returned here. This
        // keeps a web turn at 2 blocking inferences to the visible answer.
        return [
            'evidence'  => $fit['evidence'],
            'sourceIds' => $sourceIds,
            'sourceMap' => $sourceMap,
            'backingChunks' => $allChunks,
            'selectedChunks' => $selected,
        ];
    }

    /**
     * Convert bridge entity JSON into WebChunk[] for BM25 + evidence fitting.
     *
     * @param array{entities?: array<int, array{entity_id?: string, entity_type?: string, author?: string, score?: int, body?: string, published?: string}>} $content
     * @param string $sourceId Source identifier (e.g. "S1")
     * @param string $sourceUrl The fetched URL
     * @return WebChunk[]
     */
    private function chunksFromBridgeContent(array $content, string $sourceId, string $sourceUrl): array
    {
        $entities = $content['entities'] ?? [];
        if (empty($entities)) {
            return [];
        }

        $chunks = [];
        $chunkNum = 0;
        $domain = parse_url($sourceUrl, PHP_URL_HOST) ?: '';
        $fetchedAt = date('c');
        $title = $content['title'] ?? '';
        $finalUrl = $content['url'] ?? $sourceUrl;

        foreach ($entities as $entity) {
            $body = $entity['body'] ?? '';
            if (empty(trim($body))) {
                continue;
            }

            $entityId   = $entity['entity_id'] ?? null;
            $entityType = $entity['entity_type'] ?? null;
            $author     = $entity['author'] ?? null;
            $score      = isset($entity['score']) ? (int) $entity['score'] : null;
            $published  = $entity['published'] ?? null;

            $chunkNum++;

            $chunks[] = new WebChunk(
                sourceId:    $sourceId,
                chunkId:     "{$sourceId}-C{$chunkNum}",
                url:         $sourceUrl,
                finalUrl:    $finalUrl,
                title:       $title,
                domain:      $domain,
                publishedAt: $published,
                updatedAt:   null,
                fetchedAt:   $fetchedAt,
                headingPath: [],
                sectionType: $entityType ?? 'entity',
                text:        $body,
                position:    $chunkNum,
                entityId:    $entityId,
                entityType:  $entityType,
                author:      $author,
                score:       $score,
            );
        }

        return $chunks;
    }

    private static function emptyResult(string $message): array
    {
        return ['evidence' => $message, 'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [], 'selectedChunks' => []];
    }

    // ── Three-level evidence fitting ────────────────────────────────────

    /**
     * @param WebChunk[] $chunks
     * @return array{evidence: string, chunks: WebChunk[], level: string}
     */
    private function threeLevelFit(array $chunks, string $query, array $messages): array
    {
        $contextWindow = (int) Config::get('LLM_CTX_SIZE', 32768);
        $maxWebTokens = (int) Config::get('MAX_WEB_CONTEXT_TOKENS', 8192);
        $completionReserve = 4096;
        $safetyMargin = 512;

        $systemPrompt = $this->summarySystemPrompt();
        $userTemplate = "RETRIEVED DATA:\n\n__EVIDENCE__\n\nUSER QUESTION: {$query}";
        $basePromptTokens = $this->counter->count($systemPrompt)
                          + $this->counter->count(str_replace('__EVIDENCE__', '', $userTemplate));

        $webBudget = min(
            $maxWebTokens,
            max(0, $contextWindow - $basePromptTokens - $completionReserve - $safetyMargin)
        );

        $chunkTokens = $this->counter->countChunks($chunks);
        $evidenceBlock = $this->builder->build($chunks);
        $evidenceTokens = $this->counter->count($evidenceBlock);

        // Level 1: exact chunks fit
        if ($evidenceTokens <= $webBudget) {
            return ['evidence' => $evidenceBlock, 'chunks' => $chunks, 'level' => 'exact'];
        }

        // Level 2: extractive compression
        $compressor = new ExtractiveCompressor($query);
        [$compressedTexts, $saved] = $compressor->compress($chunks, $this->counter, $webBudget, $chunkTokens);
        $compressedBlock = $this->builder->buildTexts($chunks, $compressedTexts);
        $compressedTokens = $this->counter->count($compressedBlock);

        if ($compressedTokens <= $webBudget) {
            return ['evidence' => $compressedBlock, 'chunks' => $chunks, 'level' => 'extractive'];
        }

        // Level 3: per-source LLM condenser
        $condenserChunks = array_filter($chunks, fn($c) => strlen($c->text) < 50000);
        if (count($condenserChunks) < count($chunks)) {
            $condenserChunks = array_values($condenserChunks);
        }

        $ledger = $this->sourceCondenser->condense(
            empty($condenserChunks) ? $chunks : $condenserChunks,
            $query
        );

        $ledgerLines = [];
        foreach ($ledger as $entry) {
            $sourceChunks = array_filter($chunks, fn($c) => $c->sourceId === $entry['sourceId']);
            $first = reset($sourceChunks);
            if (!$first) continue;

            $ledgerLines[] = "<source id=\"{$entry['sourceId']}\">";
            $ledgerLines[] = "<domain>{$first->domain}</domain>";

            foreach ($entry['items'] as $item) {
                $refs = implode(',', $item['chunkIds']);
                $ledgerLines[] = "<claim refs=\"{$refs}\">";
                $ledgerLines[] = htmlspecialchars($item['claim'], ENT_XML1);
                $ledgerLines[] = '</claim>';
            }
            $ledgerLines[] = '</source>';
            $ledgerLines[] = '';
        }

        $fullLedger = implode("\n", $ledgerLines);
        $ledgerTokens = $this->counter->count($fullLedger);

        if ($ledgerTokens > $webBudget && count($ledger) > 1) {
            usort($ledger, fn($a, $b) => count($a['items']) <=> count($b['items']));
            while ($ledgerTokens > $webBudget && count($ledger) > 1) {
                array_pop($ledger);
                $slimLines = [];
                foreach ($ledger as $entry) {
                    $sourceChunks = array_filter($chunks, fn($c) => $c->sourceId === $entry['sourceId']);
                    $first = reset($sourceChunks);
                    if (!$first) continue;
                    $slimLines[] = "<source id=\"{$entry['sourceId']}\">";
                    foreach ($entry['items'] as $item) {
                        $slimLines[] = '<claim refs="' . implode(',', $item['chunkIds']) . '">';
                        $slimLines[] = htmlspecialchars($item['claim'], ENT_XML1);
                        $slimLines[] = '</claim>';
                    }
                    $slimLines[] = '</source>';
                    $slimLines[] = '';
                }
                $fullLedger = implode("\n", $slimLines);
                $ledgerTokens = $this->counter->count($fullLedger);
            }
        }

        return ['evidence' => $fullLedger, 'chunks' => $chunks, 'level' => 'condenser'];
    }

    private function summarySystemPrompt(): string
    {
        return <<<'PROMPT'
You are a helpful AI assistant. Answer the user's question using only the provided
RETRIEVED DATA. Cite source IDs [S1] where applicable. If evidence is incomplete
or conflicting, say so. Do not use knowledge outside the provided data.
PROMPT;
    }

    private function emitProgress(string $stage, string $text, callable $emit, string $url = ''): void
    {
        if (class_exists('\\App\\ProgressWriter')) {
            \App\ProgressWriter::write($this->sessionId, $stage, $text, 'slate', $url);
        }
    }

    /**
     * Heuristic: does the query read as a simple factual lookup rather
     * than a comparison, recommendation, or multi-aspect question?
     *
     * Returns false (NOT simple → fetch more sources) when comparison or
     * recommendation markers are present. Returns true for everything else
     * — the default is to trust the hard cap, not this heuristic.
     */
    private function queryIsSimple(string $query): bool
    {
        $complexPatterns = [
            '/\bvs\.?\b/i',               // "vs" / "vs."
            '/\bversus\b/i',              // "versus"
            '/\bcompare(d)?\b/i',         // "compare" / "compared"
            '/\bdifference between\b/i',  // "difference between"
            '/\bbetter\b/i',              // "better" — implies comparison
            '/\bbest\b/i',                // "best" — requires breadth
            '/\bpros?.?\s*(?:and|\&)\s*cons?\b/i', // "pros and cons"
        ];
        foreach ($complexPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return false;
            }
        }
        return true;
    }
}
