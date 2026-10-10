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

    /** Normalized URL => source id, for the pages already read earlier in this turn. */
    private array $alreadyRead = [];

    /**
     * Pages already fetched and numbered earlier in this turn, keyed by normalized URL.
     *
     * One tool call is one turn's evidence: when a second sub-query surfaces a page the first one already read,
     * that page keeps its id and is not fetched again. Without this each sub-query started a fresh pipeline with
     * an empty deduplicator, so the same URL was re-scraped and minted a new S# (measured: one 3-query turn read
     * huggingface.co three times and benchlm.ai twice, giving 7 registrations for 4 pages).
     *
     * @param array<string, string> $byUrl normalized url => source id
     */
    public function setAlreadyRead(array $byUrl): void
    {
        $this->alreadyRead = $byUrl;
    }

    /**
     * The id a page already carries in this turn, or null when it is new to the turn. Identity is the repo's URL
     * key (same page as the direct path's "fetched once"), plus www-equivalence: the SERP and the page's own
     * links spell the same host both ways, and one page must resolve to one id however it is written.
     */
    public static function turnUrlKey(string $url): string
    {
        return preg_replace('#^https?://www\.#', 'https://', UrlIndex::key($url)) ?? $url;
    }

    public static function alreadyReadId(string $url, array $byUrl): ?string
    {
        $key = self::turnUrlKey($url);
        if ($key === '' || !isset($byUrl[$key])) {
            return null;
        }
        return (string) $byUrl[$key];
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
            ], 'error', 'SearchPipeline::run');
            return self::emptyResult('Web search is unavailable: the browser bridge is not connected (Edge extension or relay is offline).');
        }

        return $this->runBridgeMode($query, $messages, $emit, $bridge);
    }

    // ── Direct sources (URLs the user pasted, no SERP) ──────────────────

    /**
     * Fetch the URLs the user supplied directly — no search engine, no candidate ranking.
     *
     * Same per-source machinery as the search path (chunk building, duplicate-source check, BM25 when a
     * question is present, three-level budget fitting), so a pasted link produces exactly the evidence shape a
     * search produces and rides the same persistence, citations and Context Data.
     *
     * Never mass-fetches on its own: the caller passes the URLs the user approved. Indexing, normalisation and
     * deduplication happen here (UrlIndex) so one URL is fetched once even if it appears ten times, and a page
     * already parsed earlier in the session is skipped in favour of the chunks that already exist.
     *
     * @param string[] $urls      approved URLs (raw text is fine; they are re-indexed here)
     * @param string   $query     the user's question, '' when they sent only a link
     * @param string[] $alreadyParsed URLs already fetched in this session (reuse, do not re-fetch)
     * @return array{evidence:string,sourceIds:string[],sourceMap:array,backingChunks:WebChunk[],selectedChunks:WebChunk[],failed:array,fitLevel:string,condenserCalls:int,counts:array}
     */
    public function fetchDirect(array $urls, string $query, array $messages, callable $emit, array $alreadyParsed = []): array
    {
        $index = UrlIndex::index(implode("\n", $urls));
        $candidates = array_values(array_filter($index['entries'], static fn(array $e): bool => $e['fetchable']));
        $skipped = array_values(array_filter($index['entries'], static fn(array $e): bool => !$e['fetchable']));

        $split = UrlIndex::excluding($candidates, $alreadyParsed);
        $targets = $split['fresh'];
        $max = (int) Config::get('MAX_DIRECT_SOURCES', 0);   // 0 = no ceiling; a valve, never surfaced
        if ($max > 0 && count($targets) > $max) {
            $targets = array_slice($targets, 0, $max);
        }

        \App\Logger::logEvent('direct_fetch_requested', 'Direct source fetch requested', [
            'requested'   => count($index['entries']),
            'unique'      => $index['counts']['unique'],
            'duplicates'  => $index['counts']['duplicates'],
            'fetchable'   => $index['counts']['fetchable'],
            'not_fetchable' => array_map(static fn(array $e): string => $e['url'] . ' (' . $e['reason'] . ')', $skipped),
            'already_parsed' => array_map(static fn(array $e): string => $e['url'], $split['already']),
            'to_fetch'    => count($targets),
            'question'    => $query !== '',
        ], 'info', 'SearchPipeline::fetchDirect');

        if (empty($targets)) {
            return [
                'evidence' => '', 'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [],
                'selectedChunks' => [], 'failed' => [], 'fitLevel' => 'none', 'condenserCalls' => 0,
                'counts' => $index['counts'] + ['already_parsed' => count($split['already'])],
            ];
        }

        $bridge = new BridgeFetcher();
        if (!$bridge->isConnected()) {
            $this->emitProgress('search_unavailable', 'Browser bridge not connected', $emit);
            \App\Logger::logEvent('direct_fetch_failed', 'Browser bridge disconnected', [
                'urls' => array_column($targets, 'url'),
            ], 'error', 'SearchPipeline::fetchDirect');
            return [
                'evidence' => 'The browser bridge is not connected, so the link could not be opened.',
                'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [], 'selectedChunks' => [],
                'failed' => [['url' => '', 'status' => 'disconnected']], 'fitLevel' => 'none',
                'condenserCalls' => 0, 'counts' => $index['counts'],
            ];
        }

        $sourceId = $this->sourceStartSeq - 1;
        $allChunks = [];
        $sourceMap = [];
        $failed = [];
        $lastHost = null;

        foreach ($targets as $entry) {
            $url = $entry['url'];
            // Pace repeat hits on one domain: the bridge navigates sequentially anyway, but firing 20
            // navigations at one host back to back is how a site starts refusing us.
            if ($lastHost !== null && $lastHost === $entry['domain']) {
                usleep(1_200_000);
            }
            $lastHost = $entry['domain'];

            $sourceId++;
            $sid = "S{$sourceId}";
            $host = $entry['domain'];
            $this->emitProgress('scraping_start', "Opening {$host}", $emit, $url);

            $t0 = microtime(true);
            $result = $bridge->fetch($url);
            $ms = (int) round((microtime(true) - $t0) * 1000);

            \App\Logger::logEvent('direct_fetch_result', 'Direct source fetch finished', [
                'url' => $url,
                'status' => $result->status,
                'ms' => $ms,
                'bytes' => is_array($result->content) ? strlen((string) json_encode($result->content)) : 0,
            ], $result->isSuccess() ? 'info' : 'warn', 'SearchPipeline::fetchDirect');

            if (!$result->isSuccess() || !$result->content) {
                $failed[] = ['url' => $url, 'status' => $result->status, 'error' => $result->error];
                \App\Logger::logEvent('direct_fetch_failed', 'Direct source could not be read', [
                    'url' => $url, 'status' => $result->status, 'error' => $result->error,
                ], 'warn', 'SearchPipeline::fetchDirect');
                $this->emitProgress('scraping_done', "{$host}: {$result->status}", $emit, $url);
                continue;
            }

            $chunks = $this->chunksFromBridgeContent($result->content, $sid, $url);
            if (empty($chunks)) {
                $failed[] = ['url' => $url, 'status' => 'no_content', 'error' => null];
                $this->emitProgress('scraping_done', "{$host}: no readable content", $emit, $url);
                continue;
            }

            $sourceBody = '';
            foreach ($chunks as $chunk) $sourceBody .= $chunk->text . "\n";
            $decision = $this->contentDeduplicator->evaluate($sourceBody);
            \App\Logger::logEvent('direct_fetch_source', 'Direct source chunked', [
                'source_id' => $sid,
                'url' => $url,
                'chunks' => count($chunks),
                'chars' => strlen($sourceBody),
                'duplicate' => (bool) $decision['duplicate'],
                'max_similarity' => round((float) $decision['max_similarity'], 4),
            ], 'info', 'SearchPipeline::fetchDirect');

            if ($decision['duplicate']) {
                $this->emitProgress('scraping_done', "{$host}: duplicate of an earlier source", $emit, $url);
                continue;
            }
            $this->contentDeduplicator->keep($sourceBody, "{$sid} {$url}");

            $allChunks = array_merge($allChunks, $chunks);
            $first = $chunks[0];
            $sourceMap[$sid] = [
                'url'    => $first->finalUrl ?: $url,
                'title'  => $first->title,
                'domain' => $first->domain,
            ];
            $this->emitProgress('scraping_done', "Opened {$host}", $emit, $url);
        }

        if (empty($allChunks)) {
            \App\Logger::logEvent('direct_fetch_fit', 'No usable content from the direct sources', [
                'failed' => $failed,
            ], 'warn', 'SearchPipeline::fetchDirect');
            return [
                'evidence' => '', 'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [],
                'selectedChunks' => [], 'failed' => $failed, 'fitLevel' => 'none', 'condenserCalls' => 0,
                'counts' => $index['counts'],
            ];
        }

        // A pasted link is a document the user handed over, not a search result: everything that was read goes
        // in, in document order, and the budget fit below decides how much of it survives. Ranking it against
        // the question first was silently dropping most of the page — measured: an 11-chunk thread reached the
        // model as 1 chunk (322 chars) because the question happened to match one word in one comment, and a
        // question sharing no words with the page dropped it entirely.
        $selected = $allChunks;

        $callsBefore = count($this->agent->callLog);
        $fit = $this->threeLevelFit($selected, $query !== '' ? $query : ($sourceMap ? reset($sourceMap)['title'] : ''), $messages);
        $condenserCalls = count($this->agent->callLog) - $callsBefore;

        $evidence = (string) ($fit['evidence'] ?? '');
        \App\Logger::logEvent('direct_fetch_fit', 'Direct source evidence fitted', [
            'sources' => count($sourceMap),
            'chunks' => count($allChunks),
            'selected_chunks' => count($selected),
            'fit_level' => $fit['level'] ?? 'unknown',
            'condenser_model_calls' => $condenserCalls,
            'evidence_chars' => strlen($evidence),
            'failed' => $failed,
        ], 'info', 'SearchPipeline::fetchDirect');

        return [
            'evidence'       => $evidence,
            'sourceIds'      => array_keys($sourceMap),
            'sourceMap'      => $sourceMap,
            'backingChunks'  => $allChunks,
            'selectedChunks' => $selected instanceof \ArrayObject ? iterator_to_array($selected) : $selected,
            'failed'         => $failed,
            'fitLevel'       => (string) ($fit['level'] ?? 'unknown'),
            'condenserCalls' => $condenserCalls,
            'counts'         => $index['counts'] + ['already_parsed' => count($split['already'])],
        ];
    }

    /**
     * Evidence from a document this turn produced itself — a video's transcript, above all. It goes through
     * the same chunking and fitting a fetched page goes through, so citations, Context Data, deferred
     * atomization and later `search_session_evidence` reads all work without new plumbing.
     *
     * @param array<int,array{url:string,title:string,domain:string,text:string,sectionType?:string}> $docs
     * @return array<string,mixed> the same shape fetchDirect returns
     */
    public function ingestDocument(array $docs, string $query, array $messages = []): array
    {
        $sourceId = $this->sourceStartSeq - 1;
        $allChunks = [];
        $sourceMap = [];

        foreach ($docs as $doc) {
            $text = trim((string) ($doc['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $sourceId++;
            $sid = "S{$sourceId}";
            $url = (string) ($doc['url'] ?? '');
            $domain = (string) ($doc['domain'] ?? (parse_url($url, PHP_URL_HOST) ?: ''));
            $title = (string) ($doc['title'] ?? '');
            $sourceMap[$sid] = ['domain' => $domain, 'url' => $url, 'title' => $title];
            $allChunks = array_merge($allChunks, $this->chunksFromText(
                $text, $sid, $url, $domain, $title, (string) ($doc['sectionType'] ?? 'transcript')
            ));
        }

        if ($allChunks === []) {
            return [
                'evidence' => '', 'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [],
                'selectedChunks' => [], 'failed' => [], 'fitLevel' => 'none', 'condenserCalls' => 0,
                'counts' => ['unique' => 0],
            ];
        }

        $callsBefore = count($this->agent->callLog);
        $fit = $this->threeLevelFit($allChunks, $query, $messages);
        $condenserCalls = count($this->agent->callLog) - $callsBefore;
        $selected = $fit['chunks'] ?? $allChunks;

        \App\Logger::logEvent('document_ingested', 'A document this turn produced became evidence', [
            'sources' => count($sourceMap),
            'chunks' => count($allChunks),
            'fit_level' => $fit['level'] ?? 'unknown',
            'evidence_chars' => strlen((string) ($fit['evidence'] ?? '')),
        ], 'info', 'SearchPipeline::ingestDocument');

        return [
            'evidence'       => (string) ($fit['evidence'] ?? ''),
            'sourceIds'      => array_keys($sourceMap),
            'sourceMap'      => $sourceMap,
            'backingChunks'  => $allChunks,
            'selectedChunks' => $selected instanceof \ArrayObject ? iterator_to_array($selected) : $selected,
            'failed'         => [],
            'fitLevel'       => (string) ($fit['level'] ?? 'unknown'),
            'condenserCalls' => $condenserCalls,
            'counts'         => ['unique' => count($sourceMap)],
        ];
    }

    /**
     * One chunk per paragraph, split further only when a paragraph would be heavy on its own. A transcript
     * arrives as timestamped lines and has no headings, so the heading path stays empty rather than invented.
     */
    private function chunksFromText(string $text, string $sourceId, string $url, string $domain, string $title, string $sectionType): array
    {
        $chunks = [];
        $position = 0;
        $fetchedAt = date('c');

        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            foreach (self::splitHeavy($paragraph) as $piece) {
                $position++;
                $chunks[] = new WebChunk(
                    sourceId: $sourceId,
                    chunkId: "{$sourceId}-C{$position}",
                    url: $url,
                    finalUrl: $url,
                    title: $title,
                    domain: $domain,
                    publishedAt: null,
                    updatedAt: null,
                    fetchedAt: $fetchedAt,
                    headingPath: [],
                    sectionType: $sectionType,
                    text: $piece,
                    position: $position,
                );
            }
        }

        return $chunks;
    }

    /** A paragraph too heavy to send whole is cut on its own line boundaries. */
    private static function splitHeavy(string $paragraph, int $limit = 4000): array
    {
        if (strlen($paragraph) <= $limit) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';
        foreach (preg_split('/\n/', $paragraph) ?: [] as $line) {
            if ($current !== '' && strlen($current) + strlen($line) + 1 > $limit) {
                $pieces[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n") . $line;
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
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
        /** @var array<string, string> id => url, pages this query surfaced that were read earlier in the turn */
        $reused = [];
        // Transport/disconnect failures only — the browser never navigated, so a
        // retry carries no shadow-ban risk. Never retry challenge/consent/timeout.
        $retryableStatuses = ['disconnected', 'ws_error', 'error'];
        $failed = [];

        foreach ($candidates as $candidate) {
            $knownId = self::alreadyReadId((string) $candidate->url, $this->alreadyRead);
            if ($knownId !== null) {
                // Read earlier in this turn under this id: fetching it again would pay for the same page twice
                // and mint a second id for one URL. The model already has the page above; the note below tells
                // it so, tying this query's result to the id it already carries.
                $reused[$knownId] = (string) $candidate->url;
                \App\Logger::logEvent('search_source_reused', 'Page already read in this turn — not fetched again', [
                    'query' => $query,
                    'url' => $candidate->url,
                    'source_id' => $knownId,
                ], 'info', 'SearchPipeline::runBridgeMode');
                continue;
            }

            $sourceId++;
            $sid = "S{$sourceId}";

            $shortUrl = strlen($candidate->url) > 60
                ? substr($candidate->url, 0, 57) . '...'
                : $candidate->url;

            $this->emitProgress('scraping_start', "Scraping {$shortUrl}", $emit, $candidate->url);

            $result = $bridge->fetch($candidate->url);

            if (!$result->isSuccess() && in_array($result->status, $retryableStatuses, true)) {
                usleep(400_000);
                $result = $bridge->fetch($candidate->url);
            }

            $totalBodyLen = 0;
            $entityCount = 0;
            if ($result->isSuccess() && $result->content) {
                foreach ($result->content['entities'] ?? [] as $e) {
                    $totalBodyLen += strlen($e['body'] ?? '');
                }
                $entityCount = count($result->content['entities'] ?? []);
            }
            BridgeFetchLogger::record($candidate->url, $result->status, $totalBodyLen, $entityCount, $result->error, $result->content);

            if (!$result->isSuccess()) {
                // Kept, not just logged: the chat panel tells the user which sources could not be read and
                // why (P4-S2). A failure that only reaches the log is a silent gap for the user.
                $failed[] = ['url' => $candidate->url, 'status' => (string) $result->status, 'reason' => $result->error];
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
            if ($reused !== []) {
                // Every result this query returned was already read above. That is a successful outcome, not a
                // fetch failure — say which pages answered it instead of reporting that nothing could be read.
                \App\Logger::logEvent('search_source_reused', 'All of this query\'s results were already in context', [
                    'query' => $query,
                    'source_ids' => array_keys($reused),
                ], 'info', 'SearchPipeline::runBridgeMode');
                return [
                    'evidence' => self::reuseNote($reused),
                    'sourceIds' => [],
                    'sourceMap' => [],
                    'backingChunks' => [],
                    'selectedChunks' => [],
                    'failed' => $failed,
                ];
            }
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

        // Which sites actually contributed to the final evidence vs. were
        // fetched but not selected (surfaced so the log shows per-site value,
        // not just "we scraped N pages").
        $usedSourceIds = [];
        foreach ($selected as $chunk) {
            $usedSourceIds[] = $chunk->sourceId;
        }
        $usedSourceIds = array_values(array_unique($usedSourceIds));

        \App\Logger::logEvent('bridge_evidence', 'Bridge evidence fitting complete', [
            'query' => $query,
            'total_chunks' => count($allChunks),
            'selected_chunks' => count($selected),
            'evidence_len' => strlen($fit['evidence']),
            'source_count' => count($sourceIds),
            'used_source_ids' => $usedSourceIds,
            'unused_source_ids' => array_values(array_diff($sourceIds, $usedSourceIds)),
            'reused_source_ids' => array_keys($reused),
        ], 'info', 'SearchPipeline::runBridgeMode');

        // Atomic extraction has MOVED OUT of SearchPipeline: it now runs as a
        // deferred consolidation in ChatManager (start of a later turn, gated by
        // backlog/headroom policy) over the selected chunks returned here. This
        // keeps a web turn at 2 blocking inferences to the visible answer.
        $evidence = $fit['evidence'];
        if ($reused !== []) {
            // Say it once, at the top of this query's evidence, so the answer can cite the page that already
            // holds this query's result instead of wondering why the search returned less than it did.
            $evidence = self::reuseNote($reused) . "\n" . $evidence;
        }

        return [
            'evidence'  => $evidence,
            'sourceIds' => $sourceIds,
            'sourceMap' => $sourceMap,
            'backingChunks' => $allChunks,
            'selectedChunks' => $selected,
            'failed'    => $failed,
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
        // Source dates resolved by the extension's own sweep (it reads the whole
        // document, including the header/footer the article extractor discards).
        // An entity-level date (a post or comment timestamp) still wins: it is
        // more specific than the page's own date.
        $dates = is_array($content['dates'] ?? null) ? $content['dates'] : [];

        foreach ($entities as $entity) {
            $body = $entity['body'] ?? '';
            if (empty(trim($body))) {
                continue;
            }

            $entityId   = $entity['entity_id'] ?? null;
            $entityType = $entity['entity_type'] ?? null;
            $author     = $entity['author'] ?? null;
            $score      = isset($entity['score']) ? (int) $entity['score'] : null;
            $published  = $entity['published'] ?? $dates['published'] ?? $content['date_posted'] ?? null;
            $updated    = $dates['updated'] ?? null;

            $chunkNum++;

            $chunks[] = new WebChunk(
                sourceId:    $sourceId,
                chunkId:     "{$sourceId}-C{$chunkNum}",
                url:         $sourceUrl,
                finalUrl:    $finalUrl,
                title:       $title,
                domain:      $domain,
                publishedAt: $published,
                updatedAt:   $updated,
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

    /** One line naming the pages this query found that the turn had already read, and the ids they carry. */
    private static function reuseNote(array $byId): string
    {
        $parts = [];
        foreach ($byId as $id => $url) {
            $host = (string) (parse_url((string) $url, PHP_URL_HOST) ?: $url);
            $parts[] = $id . ' (' . preg_replace('/^www\./', '', strtolower($host)) . ')';
        }
        return '(already read earlier in this turn: ' . implode(', ', $parts) . ')';
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
