<?php

namespace App\Search;

use App\AgentManager;
use App\Config;
use App\Search;
use App\Search\OutboundScheduler;

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
    private OutboundScheduler $scheduler;
    private int $sessionId = 0;

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
        $this->scheduler = new OutboundScheduler();
    }

    public function setSessionId(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Run the full search pipeline.
     *
     * Bridge connected → browser-based SERP + full page extraction.
     * Bridge disconnected → SearXNG snippets only (honest fallback).
     *
     * @return array{evidence: string, sourceIds: string[], sourceUrls: string[]}
     */
    public function run(string $query, array $messages, callable $emit): array
    {
        $bridge = new BridgeFetcher();

        if ($bridge->isConnected()) {
            return $this->runBridgeMode($query, $messages, $emit, $bridge);
        }

        return $this->runSnippetMode($query, $emit);
    }

    // ── Bridge mode (browser SERP + full extraction) ────────────────────

    private function runBridgeMode(string $query, array $messages, callable $emit, BridgeFetcher $bridge): array
    {
        $this->emitProgress('search_querying', "Querying browser search for: {$query}", $emit);

        $candidates = $bridge->searchSERP($query);
        if (empty($candidates)) {
            $this->emitProgress('search_no_results', "No browser results for: {$query}", $emit);
            return ['evidence' => '', 'sourceIds' => [], 'sourceUrls' => []];
        }

        $deduplicator = new CandidateDeduplicator();
        $candidates = $deduplicator->deduplicate($candidates);

        // ── Sequential fetch via bridge ──────────────────────────────
        $sourceId = 0;
        $allChunks = [];
        $fetchedUrls = [];

        foreach ($candidates as $candidate) {
            $sourceId++;
            $sid = "S{$sourceId}";

            $shortUrl = strlen($candidate->url) > 60
                ? substr($candidate->url, 0, 57) . '...'
                : $candidate->url;

            $this->emitProgress('scraping_start', "Scraping {$shortUrl}", $emit);

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
                $this->emitProgress('scraping_done', "Scraped {$shortUrl} ({$result->status})", $emit);
                continue;
            }

            $this->emitProgress('scraping_done', "Scraped {$shortUrl}", $emit);
            $fetchedUrls[] = $candidate->url;

            $chunks = $this->chunksFromBridgeContent($result->content, $sid, $candidate->url);
            $allChunks = array_merge($allChunks, $chunks);
        }

        if (empty($allChunks)) {
            $this->emitProgress('search_no_results', "No usable content for: {$query}", $emit);
            return ['evidence' => '', 'sourceIds' => [], 'sourceUrls' => []];
        }

        // ── BM25 retrieval ──────────────────────────────────────────
        $this->emitProgress('search_retrieving', 'Selecting relevant passages...', $emit);
        $selected = $this->retriever->rank($allChunks, $query, $query, $this->policy);

        // ── Three-level evidence fitting ────────────────────────────
        $this->emitProgress('search_condensing', 'Fitting evidence to budget...', $emit);
        $fit = $this->threeLevelFit($selected, $query, $messages);

        $sourceIds = [];
        foreach ($fit['chunks'] as $chunk) {
            $sourceIds[] = $chunk->sourceId;
        }
        $sourceIds = array_values(array_unique($sourceIds));

        return [
            'evidence' => $fit['evidence'],
            'sourceIds' => $sourceIds,
            'sourceUrls' => $fetchedUrls,
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

    // ── Snippet mode (SearXNG only, no crawling) ───────────────────────

    private function runSnippetMode(string $query, callable $emit): array
    {
        $this->emitProgress('search_querying', "Querying search engine for: {$query}", $emit);

        $this->scheduler->waitForSlot('searxng', true);
        $candidates = Search::queryCandidates($query, 12);
        if (empty($candidates)) {
            $this->emitProgress('search_no_results', "No results for: {$query}", $emit);
            return ['evidence' => '', 'sourceIds' => [], 'sourceUrls' => []];
        }

        $deduplicator = new CandidateDeduplicator();
        $candidates = $deduplicator->deduplicate($candidates);

        $evidence = EvidenceBuilder::fromSnippets($candidates);

        if (empty($evidence)) {
            $this->emitProgress('search_no_results', "No usable content for: {$query}", $emit);
            return ['evidence' => '', 'sourceIds' => [], 'sourceUrls' => []];
        }

        return [
            'evidence'   => $evidence,
            'sourceIds'  => [],
            'sourceUrls' => array_map(fn(Candidate $c) => $c->url, $candidates),
        ];
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

        $ledgerLines = ["RETRIEVED DATA — UNTRUSTED EXTERNAL CONTENT:\n"];
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
                $slimLines = ["RETRIEVED DATA — UNTRUSTED EXTERNAL CONTENT:\n"];
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

    private function emitProgress(string $stage, string $text, callable $emit): void
    {
        if (class_exists('\\App\\ProgressWriter')) {
            \App\ProgressWriter::write($this->sessionId, $stage, $text, 'slate');
        }
    }

    /**
     * Extract search terms for candidate ranking.
     * @return array<string>
     */
    private function extractSearchTerms(string $query): array
    {
        $words = preg_split('/\s+/', strtolower($query));
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
                       'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
                       'can', 'could', 'should', 'may', 'might', 'to', 'of', 'in',
                       'for', 'on', 'with', 'at', 'by', 'from', 'about', 'this',
                       'that', 'it', 'its', 'and', 'or', 'not', 'but', 'if', 'so',
                       'what', 'which', 'who', 'how', 'when', 'where', 'why'];
        $stopSet = array_flip($stopWords);

        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, ".,;:!?\"'()");
            if (strlen($word) > 1 && !isset($stopSet[$word])) {
                $terms[] = $word;
            }
        }
        return array_unique($terms);
    }
}
