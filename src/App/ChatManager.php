<?php

namespace App;

use App\Database;
use App\AgentManager;
use App\Agents\AtomizationPolicy;
use App\Config;
use App\Search\CitationValidator;
use App\Search\ChunkMap;
use App\Search\SourceCondenser;
use App\Search\TokenCounter;
use App\Search\WebChunk;
use App\Services\AtomizationStats;
use App\Services\FileAttachmentService;
use App\Services\FileContextService;
use App\Services\ModelLock;
use App\Services\PromptAssemblyService;
use App\Services\ToolExecutionService;

class ChatManager
{
    private const OUTPUT_RESERVE_TOKENS = 4096;
    private const SAFETY_MARGIN_TOKENS = 256;

    /**
     * How many tool rounds one user turn may take. The loop exists so the model can act on what it just
     * received (look at the stored sources, then read the passage the list names), and the cap exists
     * because a small model will otherwise keep asking forever: past this many rounds the turn is forced
     * to answer from what it has.
     */
    private const MAX_TOOL_ROUNDS = 3;

    /**
     * Experiment scaffolding (2026-10-11): the reviewed tool descriptions measured against the previous wording in
     * one model visit. Set by the live harness, never by the app. Remove once the question is settled.
     */
    public static bool $legacyToolDescriptions = false;
    /**
     * The whole answer when a watch produced nothing to read. The backend says it, in its own voice, with no
     * model call: a model asked to answer anyway reaches for the title, the uploader and the view count
     * instead of admitting it could not watch the video (measured 2026-10-09, session 1002382).
     */
    private const WATCH_REFUSAL = 'Sorry, I could not watch this video for you.';

    private Database $db;
    private AgentManager $agent;
    private FileAttachmentService $fileAttachmentService;
    private FileContextService $fileContext;
    private PromptAssemblyService $promptAssemblyService;
    private ToolExecutionService $toolExecutionService;
    private AtomizationStats $atomizationStats;
    /**
     * The `data_fetching` rows this turn wrote that carry chunks worth condensing (direct link fetches and tool
     * results — not attachments or video notices, which carry no chunks and are skipped by the same rule in the
     * deferred pass). Read by the caller after the turn to enqueue the enrichment job; see
     * `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
     */
    private array $turnFetchRowIds = [];
    private string $uploadDir;
    /** @var callable(string):int */
    private $countTokens;

    public function __construct(
        Database $db,
        AgentManager $agent,
        ?callable $countTokens = null
    ) {
        $this->db = $db;
        $this->agent = $agent;
        $this->countTokens = $countTokens ?? [new TokenCounter(), 'count'];
        $this->uploadDir = __DIR__ . '/../uploads/';

        $this->fileAttachmentService = new FileAttachmentService($db, $agent, $this->uploadDir);
        $this->fileContext = new FileContextService($db, $this->uploadDir);
        $this->promptAssemblyService = new PromptAssemblyService($this->db, $this->uploadDir);
        $this->toolExecutionService = new ToolExecutionService($db, $agent, $this->uploadDir);
        $this->atomizationStats = new AtomizationStats($db);
    }

    /**
     * @return string[] physical names referenced as [File: ...] markers.
     * Mirrors PromptAssemblyService's marker regex (physical_name is the
     * uploads/ filename, e.g. timestamp_uniqid_original.pdf).
     */
    private function extractFileRefs(string $query): array
    {
        if (preg_match_all('/\[File:\s*([a-zA-Z0-9._-]+)\]/', $query, $m)) {
            return array_map(fn($n) => basename(str_replace('uploads/', '', $n)), $m[1]);
        }
        return [];
    }

    /**
     * Which URLs from this message should be opened, and is this a "here is a page" message?
     *
     * Two sources of truth, in priority order:
     *   • an explicit list (the older per-URL toggles) — respected exactly, even when empty, because
     *     "I turned them all off" is a decision, not a missing value;
     *   • otherwise the fallback: fetch only when the message is essentially just a link (<= 2 URLs and almost
     *     no prose). A pasted page therefore opens nothing on its own.
     *
     * The Parse links control rides in as that empty list when it is off, and the prompt is then not scanned
     * at all — the URL work happens here, on submit, never in the browser.
     *
     * @return array{urls:string[],question:string,summarize:bool,source:string,index:array}
     */
    private function resolveDirectSources(string $query, ?array $submitted): array
    {
        if ($submitted === []) {
            return [
                'urls'      => [],
                'question'  => trim($query),
                'summarize' => false,
                'source'    => 'none',
                'index'     => ['entries' => [], 'counts' => []],
            ];
        }

        $index = \App\Search\UrlIndex::index($query);
        // Pages and videos are both claimed by this turn; a video is simply routed to the watch path below.
        $fetchable = array_values(array_filter($index['entries'], static fn(array $e): bool =>
            $e['fetchable'] || ($e['reason'] ?? '') === 'video'));
        // the app's own link rule, evaluated here on submit: one implementation, one place
        $defaults = \App\Search\UrlIndex::defaultSelection($index, $query);

        $urls = [];
        $source = 'none';
        if ($submitted !== null) {
            $approvedKeys = [];
            foreach ($submitted as $u) {
                $key = \App\Search\UrlIndex::key((string) $u);
                if ($key !== '') $approvedKeys[$key] = true;
            }
            foreach ($fetchable as $e) {
                if (isset($approvedKeys[$e['key']])) $urls[] = $e['url'];
            }
            $source = 'strip';
        } elseif ($defaults['basically_a_link']) {
            $urls = array_column($fetchable, 'url');
            $source = 'fallback';
        }

        return [
            'urls'      => array_values(array_unique($urls)),
            'question'  => $defaults['prose'],
            'summarize' => $defaults['summarize'],
            'source'    => $source,
            'index'     => $index,
        ];
    }

    /**
     * The links in this message split into videos and pages. A video is not a page, so the two never take
     * each other's route; a video already watched in this session is left alone, because its transcript is
     * already evidence and `search_session_evidence` reads it back when it matters.
     *
     * @return array{0:string[],1:string[]}
     */
    private static function splitVideoUrls(array $urls, array $alreadyParsed): array
    {
        $seen = [];
        foreach ($alreadyParsed as $parsed) {
            $key = \App\Search\UrlIndex::key((string) $parsed);
            if ($key !== '') {
                $seen[$key] = true;
            }
        }

        $videos = [];
        $pages = [];
        foreach ($urls as $url) {
            $url = (string) $url;
            if (!\App\Search\VideoSources::isVideo($url)) {
                $pages[] = $url;
                continue;
            }
            $key = \App\Search\UrlIndex::key($url);
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $videos[] = $url;
        }

        return [$videos, $pages];
    }

    /**
     * Watch the video links and hand their transcripts over as evidence, in the same shape a fetched page
     * produces — same row, same source ids, same citations, same Context Data. The stop button reaches the
     * job through the connection check: the browser going away is a cancellation, not a hang-up.
     */
    private function watchVideos(
        \App\Search\SearchPipeline $pipeline,
        int $sessionId,
        array $urls,
        string $query,
        array $direct,
        callable $emit
    ): array {
        $watch = (new \App\Services\VideoWatchIngest($this->agent))->ingest(
            $urls,
            $query,
            $emit,
            static fn(): bool => !connection_aborted()
        );

        \App\Logger::logEvent('video_watch_turn', 'Video links handled for this turn', [
            'session_id' => $sessionId,
            'urls'       => $urls,
            'watched'    => $watch['watched'],
            'failed'     => $watch['failed'],
        ], $watch['watched'] > 0 ? 'info' : 'warn', 'ChatManager::watchVideos');

        if ($watch['docs'] === []) {
            // The watch's own failures come first so they survive the union: nothingFetched() carries a 'failed'
            // key too, and in a union the left side wins — dropping them left the failed video out of the source
            // panel and out of the reason the turn could tell the user.
            return ['failed' => $watch['failed']] + self::nothingFetched();
        }

        $ingested = $pipeline->ingestDocument(
            $watch['docs'],
            $direct['question'],
            [['role' => 'user', 'content' => $query]]
        );
        $ingested['failed'] = array_merge((array) ($ingested['failed'] ?? []), $watch['failed']);

        return $ingested;
    }

    /** Two groups of evidence into one turn's worth: the transcripts and the pages share the row. */
    private static function mergeFetched(array $a, array $b): array
    {
        $rank = ['exact' => 4, 'extractive' => 3, 'condensed' => 2, 'none' => 1];

        return [
            'evidence'       => trim((string) ($a['evidence'] ?? '') . "\n\n" . (string) ($b['evidence'] ?? '')),
            'sourceIds'      => array_merge((array) ($a['sourceIds'] ?? []), (array) ($b['sourceIds'] ?? [])),
            'sourceMap'      => ((array) ($a['sourceMap'] ?? [])) + ((array) ($b['sourceMap'] ?? [])),
            'backingChunks'  => array_merge((array) ($a['backingChunks'] ?? []), (array) ($b['backingChunks'] ?? [])),
            'selectedChunks' => array_merge((array) ($a['selectedChunks'] ?? []), (array) ($b['selectedChunks'] ?? [])),
            'failed'         => array_merge((array) ($a['failed'] ?? []), (array) ($b['failed'] ?? [])),
            'fitLevel'       => ($rank[(string) ($b['fitLevel'] ?? '')] ?? 0) > ($rank[(string) ($a['fitLevel'] ?? '')] ?? 0)
                ? (string) $b['fitLevel']
                : (string) ($a['fitLevel'] ?? 'none'),
            'condenserCalls' => (int) ($a['condenserCalls'] ?? 0) + (int) ($b['condenserCalls'] ?? 0),
            'counts'         => (array) ($a['counts'] ?? []) + (array) ($b['counts'] ?? []),
        ];
    }

    /** The shape the direct path returns when there is nothing to hand over. */
    private static function nothingFetched(): array
    {
        return [
            'evidence' => '', 'sourceIds' => [], 'sourceMap' => [], 'backingChunks' => [],
            'selectedChunks' => [], 'failed' => [], 'fitLevel' => 'none', 'condenserCalls' => 0,
            'counts' => [],
        ];
    }

    /** URLs already fetched earlier in this session, read back from persisted evidence rows. */
    private function alreadyParsedUrls(int $sessionId): array
    {
        $urls = [];
        $rows = $this->db->query(
            "SELECT source_map FROM chat_history WHERE session_id = ? AND message_type = 'data_fetching'",
            [$sessionId]
        );
        foreach ($rows as $row) {
            $map = json_decode((string) ($row['source_map'] ?? ''), true);
            if (!is_array($map)) continue;
            foreach ($map as $entry) {
                if (!empty($entry['url'])) $urls[] = (string) $entry['url'];
            }
        }
        return $urls;
    }

    /**
     * Fetch the approved URLs through the browser bridge and store them as one evidence row.
     *
     * Stored exactly like a tool result (tool_name='fetch_source'), so citations, Context Data, deferred
     * atomization and later `search_session_evidence` reads all work without new plumbing. The turn framing is
     * written to the row's `turn_reminder` rather than appended to this turn's message array — the same reason
     * the tool path does it: a reminder that exists only in this turn's array makes the next prompt diverge at
     * that exact position and costs the engine the cached prefix behind it.
     *
     * @return array{ingested:bool,fit:string,condenserCalls:int,failed:array}
     */
    private function ingestDirectSources(int $sessionId, string $query, array $direct, callable $emit): array
    {
        $result = ['ingested' => false, 'fit' => 'none', 'condenserCalls' => 0, 'failed' => []];

        $pipeline = new \App\Search\SearchPipeline($this->agent);
        $pipeline->setSessionId($sessionId);
        $pipeline->setSourceStartSeq(\App\Search\SourceSequence::nextSourceSeq($this->db, $sessionId));

        $alreadyParsed = $this->alreadyParsedUrls($sessionId);
        [$videoUrls, $pageUrls] = self::splitVideoUrls($direct['urls'], $alreadyParsed);

        $fetched = null;
        if ($videoUrls !== []) {
            $fetched = $this->watchVideos($pipeline, $sessionId, $videoUrls, $query, $direct, $emit);
            $result['failed'] = $fetched['failed'];
        }

        if ($pageUrls !== []) {
            $emit('status', ['text' => count($pageUrls) === 1
                ? 'Opening the link...'
                : 'Opening ' . count($pageUrls) . ' links...']);

            $pages = $pipeline->fetchDirect(
                $pageUrls,
                $direct['question'],
                [['role' => 'user', 'content' => $query]],
                $emit,
                $alreadyParsed
            );
            $fetched = $fetched === null ? $pages : self::mergeFetched($fetched, $pages);
            $result['failed'] = array_merge($result['failed'], $pages['failed']);
        }

        $fetched ??= self::nothingFetched();

        $result['fit'] = $fetched['fitLevel'];
        $result['condenserCalls'] = (int) $fetched['condenserCalls'];
        $result['failed'] = $fetched['failed'];
        $result['evidence'] = (string) ($fetched['evidence'] ?? '');

        $evidence = (string) ($fetched['evidence'] ?? '');
        if ($evidence === '' || empty($fetched['sourceMap'])) {
            \App\Logger::logEvent('direct_fetch_no_evidence', 'Direct source produced no usable evidence', [
                'urls' => $direct['urls'],
                'failed' => $fetched['failed'],
                'reason' => $direct['source'],
            ], 'warn', 'ChatManager::ingestDirectSources');

            // A message whose only link was a video we could not read is not a page we failed to open: there is
            // nothing on the web to search for instead, so the turn is told what happened.
            if ($pageUrls === [] && $videoUrls !== []) {
                $notice = '';
                foreach ((array) $fetched['failed'] as $failure) {
                    $notice = trim((string) ($failure['notice'] ?? ''));
                    if ($notice !== '') {
                        break;
                    }
                }
                $result['videoFailure'] = $notice !== ''
                    ? $notice
                    : 'I could not read the video you linked just now.';
                // Which kind of sentence that is: one of our own limits (the duration cap, a language neither
                // model transcribes), which can follow the refusal, or a technical failure, which belongs in
                // the log and the failure row's tooltip and never in the answer.
                $result['videoFailureIsOurs'] = $notice !== '';
            }

            return $result;
        }

        $label = implode(', ', array_map(
            static fn(string $sid): string => $sid . ' ' . ($fetched['sourceMap'][$sid]['domain'] ?? ''),
            array_keys($fetched['sourceMap'])
        ));

        $directFailures = \App\Search\SourceFailures::finalize(
            $fetched['failed'] ?? [],
            $fetched['sourceMap'] ?? [],
            $evidence
        );

        $this->db->insert('chat_history', [
            'session_id'      => $sessionId,
            'role'            => 'system',
            'message'         => $evidence,
            'message_type'    => 'data_fetching',
            'tool_name'       => 'fetch_source',
            'search_query'    => mb_substr($label, 0, 250),
            'source_map'      => json_encode($fetched['sourceMap']),
            'backing_chunks'  => !empty($fetched['backingChunks']) ? json_encode($fetched['backingChunks'], JSON_UNESCAPED_UNICODE) : null,
            'selected_chunks' => !empty($fetched['selectedChunks']) ? json_encode($fetched['selectedChunks'], JSON_UNESCAPED_UNICODE) : null,
            'failed_sources'  => $directFailures ? json_encode($directFailures, JSON_UNESCAPED_UNICODE) : null,
            'atomic_context'  => null,
            'token_estimate'  => ($this->countTokens)($evidence),
        ]);

        $historyId = (int) $this->db->getConnection()->lastInsertId();
        if (!empty($fetched['selectedChunks']) || !empty($fetched['backingChunks'])) {
            $this->turnFetchRowIds[] = $historyId;
        }

        $reminder = $direct['summarize']
            ? "SOURCE HANDED OVER BY THE USER:\nThe user sent this source without a question. Summarise what it contains in a few short sections, state its publication or update date if the source has one, and offer to dig into any part of it."
            : "RUNTIME REMINDER:\nAnswer the user's original request using the evidence above. Request: \"{$direct['question']}\"";
        $this->db->update('chat_history', ['turn_reminder' => $reminder], ['id' => $historyId]);

        $emit('context_data_added', [
            'id'             => $historyId,
            'label'          => $label,
            'tool_name'      => 'fetch_source',
            'query'          => $label,
            'source_count'   => count($fetched['sourceMap']),
            'token_estimate' => ($this->countTokens)($evidence),
            'active'         => true,
        ]);

        \App\Logger::logEvent('direct_fetch_ingested', 'Direct source stored as evidence', [
            'row_id' => $historyId,
            'sources' => array_keys($fetched['sourceMap']),
            'evidence_chars' => strlen($evidence),
            'fit_level' => $fetched['fitLevel'],
            'condenser_model_calls' => $fetched['condenserCalls'],
            'summarize' => $direct['summarize'],
            'how' => $direct['source'],
        ], 'info', 'ChatManager::ingestDirectSources');

        $result['ingested'] = true;
        return $result;
    }

    public function process(int $sessionId, string $query, ?array $imageFile, ?string $activeEditFile = null, ?string $effort = null, ?callable $streamCallback = null, ?array $fetchUrls = null, ?array $adoptedRowIds = null): array
    {
        $emit = function(string $event, array $data = []) use ($streamCallback) {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        // Resolve the per-submit choices before anything reads them: the editor flag decides the tool surface and the
        // context preflight, so a choice that lives on the row has to be in hand before either runs.
        $turnChoices = $this->resolveTurnChoices($adoptedRowIds ?? [], $effort, $activeEditFile, $fetchUrls);
        $effort = $turnChoices['effort'];
        $activeEditFile = $turnChoices['edit_file'];
        $fetchUrls = $turnChoices['fetch_urls'];

        $overflow = $this->preflightContext($sessionId, $query, !empty($activeEditFile));
        if ($overflow !== null) {
            \App\Logger::logEvent('context_overflow', 'Turn rejected: context limit exceeded before processing', [
                'total' => $overflow['total'] ?? null,
                'max' => $overflow['max'] ?? null,
                'output_reserve' => $overflow['output_reserve'] ?? null,
            ], 'warn', 'ChatManager::process');
            $emit('context_overflow', $overflow);
            return [
                'status' => 'context_overflow',
                'message' => $overflow['message'],
            ];
        }

        $lockToken = ModelLock::acquireOrBusy(ModelLock::PROCESS_TTL_MS);
        // Only now, with the engine in hand, does the turn announce itself. Emitted before the lock it said
        // "Initializing..." for an attempt that immediately found the engine busy and stood down — overwriting, in the
        // bubble of a message that is still waiting its turn, the sentence that says so (measured: the spinner read
        // "Initializing..." while the queue held the message and nothing was running).
        $emit('status', ['text' => 'Initializing...']);
        // The turn is also announced outside the lock: background work must be able to tell "a user's turn is in
        // flight" even in the gap between this turn's tool call and its answer, when the lock happens to be free.
        \App\Services\ActiveTurns::touch($sessionId);
        // "A turn is in flight for this conversation" — distinct from the engine lock, which the worker also holds. The
        // stop control needs to know whether something is going to append the stop notice itself, or whether it must
        // write it (plan Task 8b). Cleared in the finally, so every exit path (including a throw) clears it.
        Cache::set('turn_running:' . $sessionId, '1', 120);
        try {
            return $this->processLocked($sessionId, $query, $imageFile, $activeEditFile, $effort, $emit, $fetchUrls, $adoptedRowIds ?? [], $turnChoices);
        } finally {
            Cache::delete('turn_running:' . $sessionId);
            \App\Services\ActiveTurns::clear($sessionId);
            \App\Services\LoopQueue::closeFor($sessionId);
            ModelLock::release($lockToken);
        }
    }

    /**
     * The ids of the fetch rows this turn wrote that carry chunks worth condensing. Read by the caller once the
     * answer has been delivered, to enqueue the enrichment job (see the plan named on the property).
     *
     * @return int[]
     */
    public function turnFetchRowIds(): array
    {
        return $this->turnFetchRowIds;
    }

    private function processLocked(int $sessionId, string $query, ?array $imageFile, ?string $activeEditFile, ?string $effort, callable $emit, ?array $fetchUrls = null, array $adoptedRowIds = [], array $turnChoices = []): array
    {
        $this->ensureSessionExists($sessionId);
        $this->turnFetchRowIds = [];

        $historyCountForTurn = (int)($this->db->query("SELECT COUNT(*) c FROM chat_history WHERE session_id = ?", [$sessionId])[0]['c'] ?? 0);
        \App\Logger::logEvent('turn_start', "Turn start (session {$sessionId})", [
            'is_first_turn' => $historyCountForTurn === 0,
            'editor_mode' => !empty($activeEditFile),
            'history_rows' => $historyCountForTurn,
            // What this turn actually ran with, and where each choice came from (caller / row / default), so a lost
            // choice is visible here instead of being inferred from behaviour.
            'choices' => $turnChoices === [] ? null : $turnChoices,
        ], 'info', 'ChatManager::processLocked');

        // Per-turn performance capture: reset the agent's call log and wrap the
        // emit callback to record time-to-first-token (first 'token' event).
        $this->agent->resetCallLog();
        $turnStart = microtime(true);
        $firstTokenTs = null;
        $origEmit = $emit;
        $contentHashCtx = hash_init('sha256');
        $contentBytes = 0;
        $emit = function (string $event, array $data = []) use ($origEmit, &$firstTokenTs, &$contentHashCtx, &$contentBytes) {
            if ($event === 'token') {
                if ($firstTokenTs === null) {
                    $firstTokenTs = microtime(true);
                }
                if (isset($data['chunk'])) {
                    hash_update($contentHashCtx, $data['chunk']);
                    $contentBytes += strlen($data['chunk']);
                }
            }
            $origEmit($event, $data);
        };

        // Deferred evidence hygiene: if un-atomized raw evidence has piled up
        // past the backlog threshold (or context headroom is tight), atomize it
        // now — before this turn's messages are built — so the atoms are
        // injected instead of raw. Runs per tool result, never as one giant
        // mixed batch (keeps the condenser's job narrow for small models).
        $this->atomizeBacklogIfNeeded($sessionId, $emit);

        // Always insert user message — tools are always available, no per-turn activation.
        // EXCEPT when this turn is answering messages the user already sent while the engine was busy (a batch): those
        // are persisted as their own rows and held out of the conversation, so inserting another row would leave a
        // duplicate bubble and send the same words twice. The held rows become the turn's transcript entry instead, and
        // they turn into ordinary messages once the answer lands (plan R11 / Task 10).
        $imagePath = null;
        if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $emit('status', ['text' => 'Processing attachment...']);
        }

        // The user row(s) this turn is answering: its own if it writes one, otherwise the held rows it adopted.
        // They become the turn's terminal link below, so "is this turn answered" is a query rather than a guess.
        $ownUserRowId = 0;
        if ($adoptedRowIds === []) {
            $written = $this->persistUserTurn($sessionId, $query, $imageFile);
            $ownUserRowId = $written['row_id'];
            $imagePath = $written['image_path'];
        } else {
            \App\Logger::logEvent('batch_adopted', 'Turn is answering messages already stored as held rows', [
                'rows' => $adoptedRowIds,
                'chars' => mb_strlen($query),
            ], 'info', 'ChatManager::processLocked');

            // An adopted row may itself carry an attachment (a queued turn that was sent with an image, or a held batch):
            // the path lives on the row, so it is read back rather than assumed absent — otherwise the model answers a
            // question about a picture it was never shown.
            $adoptedImages = $this->db->query(
                'SELECT image_path FROM chat_history WHERE id IN ('
                    . implode(',', array_fill(0, count($adoptedRowIds), '?'))
                    . ') AND image_path IS NOT NULL ORDER BY id ASC LIMIT 1',
                array_map('intval', $adoptedRowIds)
            );
            if ($adoptedImages !== []) {
                $imagePath = (string)$adoptedImages[0]['image_path'];
            }
        }
        $terminalUserRowIds = $adoptedRowIds !== []
            ? array_map('intval', $adoptedRowIds)
            : ($ownUserRowId > 0 ? [$ownUserRowId] : []);

        // Persist attached/referenced file content as managed Context Data.
        // Content is the raw original (document .txt sidecar) or image OCR text,
        // injected via the evidence path on this turn. The [File: ...] marker and
        // image_path stay untouched on the stored user message (UI flow unchanged).
        $fileRefs = $this->extractFileRefs($query);
        if ($imagePath !== null) {
            $fileRefs[] = basename($imagePath);
        }

        // ── Direct sources: URLs the user pasted ─────────────────────────────
        // A pasted link is an explicit request for that page, so it is fetched here — before the prompt is
        // assembled — and stored as evidence exactly like a tool result. Nothing is fetched unless the user
        // approved it (the composer strip sends the ON list) or the message is essentially just a link.
        $failedSources = [];
        $turnEvidence = '';
        // Set when the turn's only link was a video that could not be read: there is nothing to search for in
        // its place, so the turn says what happened instead of looking elsewhere.
        $unwatchedVideo = '';
        $videoFailureIsOurs = false;
        $direct = $this->resolveDirectSources($query, $fetchUrls);
        if (!empty($direct['urls'])) {
            $directResult = $this->ingestDirectSources($sessionId, $query, $direct, $emit);
            $failedSources = array_merge($failedSources, $directResult['failed'] ?? []);
            $turnEvidence .= (string) ($directResult['evidence'] ?? '');
            $unwatchedVideo = (string) ($directResult['videoFailure'] ?? '');
            $videoFailureIsOurs = (bool) ($directResult['videoFailureIsOurs'] ?? false);
        }

        foreach (array_unique($fileRefs) as $physicalName) {
            $file = $this->fileContext->lookupContent($physicalName);
            if ($file === null) {
                continue;
            }
            $this->db->insert('chat_history', [
                'session_id'     => $sessionId,
                'role'           => 'system',
                'message'        => $file['content'],
                'message_type'   => 'data_fetching',
                'tool_name'      => 'file',
                'search_query'   => $file['title'],
                'atomic_context' => null,
                'token_estimate' => ($this->countTokens)($file['content']),
            ]);
            $historyId = (int) $this->db->getConnection()->lastInsertId();
            $emit('context_data_added', [
                'id' => $historyId,
                'label' => $file['title'],
                'tool_name' => 'file',
                'query' => $file['title'],
                'token_estimate' => ($this->countTokens)($file['content']),
                'active' => true,
            ]);
        }

        if ($unwatchedVideo !== '') {
            // The reason travels as this turn's context, so the answer can say it: the launcher's sentence is
            // written for the user, and a turn with no evidence at all would otherwise invent one.
            $this->db->insert('chat_history', [
                'session_id'     => $sessionId,
                'role'           => 'system',
                'message'        => $unwatchedVideo,
                'message_type'   => 'data_fetching',
                'tool_name'      => 'video_watch',
                'search_query'   => mb_substr($unwatchedVideo, 0, 250),
                'atomic_context' => null,
                'token_estimate' => ($this->countTokens)($unwatchedVideo),
            ]);
            $emit('context_data_added', [
                'id' => (int) $this->db->getConnection()->lastInsertId(),
                'label' => $unwatchedVideo,
                'tool_name' => 'video_watch',
                'query' => $unwatchedVideo,
                'token_estimate' => ($this->countTokens)($unwatchedVideo),
                'active' => true,
            ]);
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        // Named on the first user turn. Evidence rows for that same turn (a pasted link, an attached
        // file) are already in $history, so the row count is not the first-turn signal — the
        // user-row count is. The guard lives in autoGenerateTitle.
        $updatedTitle = $this->autoGenerateTitle($sessionId, $query, $history, $emit);

        // A watch that produced nothing to read IS the turn: no transcript arrived, and the page about the
        // video is not its content. The answer is the backend's own sentence, streamed like any other so the
        // bubble, the metrics and the client's stream check behave the same — and there is no model call and no
        // tool, because a model asked to answer anyway fills the gap with the title, the uploader and the view
        // count, which the user already had (measured 2026-10-09, session 1002382).
        if ($unwatchedVideo !== '') {
            $refusal = self::WATCH_REFUSAL;
            if ($videoFailureIsOurs) {
                // Our own limit (the duration cap, a language neither model transcribes) is a fact about our
                // side, not metadata about the video, so the user is told which one it was.
                $refusal .= ' ' . $unwatchedVideo;
            }

            $emit('status', ['text' => 'Nothing to read from that video']);
            $emit('token', ['chunk' => $refusal]);

            $this->db->insert('chat_history', [
                'session_id'     => $sessionId,
                'role'           => 'assistant',
                'message'        => $refusal,
                'image_path'     => null,
                'token_estimate' => ($this->countTokens)($refusal),
                'search_query'   => null,
                'source_map'     => null,
                'model'          => $this->agent->getModelName(),
                'had_tool_calls' => 0,
            ]);
            $refusalRowId = (int) $this->db->getConnection()->lastInsertId();

            // The refusal sentence is this turn's terminal row: the link is what keeps boot recovery from answering
            // the same turn a second time.
            (new \App\Services\TurnAnswers($this->db))->record($terminalUserRowIds, $refusalRowId);

            $totalSessionTokens = 0;
            foreach ($this->db->selectSafe('chat_history', ['session_id' => $sessionId]) as $row) {
                $totalSessionTokens += (int) ($row['token_estimate'] ?? 0);
            }
            $this->db->update('chat_sessions', ['context_tokens' => $totalSessionTokens], ['id' => $sessionId]);

            $refusalMetrics = [
                'total_ms'  => (int) round((microtime(true) - $turnStart) * 1000),
                'ttft_ms'   => $firstTokenTs !== null ? (int) round(($firstTokenTs - $turnStart) * 1000) : null,
                'calls'     => $this->agent->callLog,
                'reasoning' => ['requested' => $effort, 'mode' => null, 'effort' => null],
            ];
            $this->db->update('chat_history', [
                'perf_metrics' => json_encode($refusalMetrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], ['id' => $refusalRowId]);

            // The failure row still belongs on the turn: the source panel is where the technical reason lives
            // (its tooltip), and the answer itself must not carry it.
            $refusalFailures = \App\Search\SourceFailures::finalize($failedSources, [], $turnEvidence);
            if (!empty($refusalFailures)) {
                $emit('sources', ['sources' => [], 'failed' => $refusalFailures]);
                $this->db->update('chat_history', [
                    'failed_sources' => json_encode($refusalFailures, JSON_UNESCAPED_UNICODE),
                ], ['id' => $refusalRowId]);
            }

            \App\Logger::logEvent('watch_refused', 'A watch produced nothing to read; the turn answered with the refusal sentence', [
                'session_id'     => $sessionId,
                'row_id'         => $refusalRowId,
                'reason_is_ours' => $videoFailureIsOurs,
                'reason'         => $unwatchedVideo,
                'model_calls'    => 0,
            ], 'warn', 'ChatManager::processLocked');

            \App\Logger::logEvent('stream_complete', 'Final response delivered to client', [
                'response_chars'       => mb_strlen($refusal),
                'total_ms'             => $refusalMetrics['total_ms'],
                'ttft_ms'              => $refusalMetrics['ttft_ms'],
                'calls'                => 0,
                'sources'              => 0,
                'total_session_tokens' => $totalSessionTokens,
                'refused_watch'        => true,
            ], 'info', 'ChatManager::processLocked');

            $emit('done', [
                'message'              => $refusal,
                'title'                => $updatedTitle,
                'total_session_tokens' => $totalSessionTokens,
                'session_id'           => $sessionId,
                'message_id'           => $refusalRowId,
                'had_tool_calls'       => false,
                'sources'              => [],
                'perf_metrics'         => $refusalMetrics,
                'content_hash'         => hash_final($contentHashCtx),
                'content_length'       => $contentBytes,
            ]);

            return [
                'status'  => 'success',
                'message' => $refusal,
                'title'   => $updatedTitle,
                'meta'    => ['search_triggered' => false, 'search_query' => null],
            ];
        }

        $isEditorMode = !empty($activeEditFile);
        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($isEditorMode);
        $currentTime = $this->promptAssemblyService->currentTimeContextLine();
        $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, [], $currentTime);

        $contextMessageCount = count($currentMessages) - 1;

        $emit('context_assembled', [
            'message_count' => $contextMessageCount,
            'has_search_context' => false,
        ]);

        \App\Logger::logEvent('prompt_assembled', 'System prompt + message array assembled', [
            'message_count' => count($currentMessages),
            'context_message_count' => $contextMessageCount,
            'system_prompt_chars' => mb_strlen($systemPrompt),
        ], 'info', 'ChatManager::processLocked');

        $sourceMap = [];
        $this->toolExecutionService->resetSourceMap();
        $this->toolExecutionService->resetBackingChunks();
        $this->toolExecutionService->resetSelectedChunks();
        $this->toolExecutionService->resetRetrievedSourceIds();
        $this->toolExecutionService->resetEvidenceCallStats();
        $this->toolExecutionService->resetCreatedTasks();

        // Transient session-evidence retrieval is injected for this turn only.
        $transientEvidence = [];
        $transientSourceIds = [];

        // Integrated first pass: the normal assistant with tools attached. On a
        // no-tool turn the answer streams live (reasoning streams via the
        // reasoning SSE); on a tool turn it assembles tool_calls, we execute
        // them, then run a single second inference over the acquired evidence.
        [$reasoningMode, $reasoningEffort] = $this->reasoningModeEffort($effort);

        // ── The forced contents browse (owner's ruling, 2026-10-11) ────────────────────────────────────────
        // At the model's MAXIMUM reasoning level the app does not merely *ask* the model to browse: it runs the BM25
        // phrase search over the session's raw chunks and hands over the contents index, so both sit in front of the
        // model from its first pass — and it can name the passages it wants next, which the ordinary read-by-id round
        // loads (duplicates included; the model resolves those when it summarises).
        //
        // Why forced: on a small model the request alone does nothing. Measured on a 4B — a strong "re-read the raw
        // evidence" prompt produced zero tool calls while the answer *claimed* the review. With the material in front
        // of it, a model that never asks for more still has the index.
        //
        // Derived from the published policy through the enums: graduated families force at the top of their own ladder
        // (qwen: high), binary families (`on`) at any thinking level. Nothing is forced when the policy is silent.
        $browseAt = $this->forcedBrowseAt();
        $effort = \App\Enums\ReasoningEffort::fromSlider($reasoningEffort);
        $thinking = $effort !== null && $effort !== \App\Enums\ReasoningEffort::Off;
        $threshold = $browseAt === null ? null : \App\Enums\ReasoningEffort::tryFrom($browseAt);
        $atMaxEffort = $thinking && $browseAt !== null
            && ($browseAt === 'on' || ($threshold !== null && $effort->atLeast($threshold)));
        if (!$isEditorMode && $atMaxEffort && $this->sessionHasStoredMaterial($sessionId)) {
            try {
                $quiet = static function (string $event, array $data = []): void {};
                $hits = $this->toolExecutionService->executeToolByName('search_session_evidence', ['queries' => [$query]], $sessionId, $quiet);

                // The index rides ONCE. The tool already appends the contents index to every search result, so the
                // separate ledger load is made only when that result did not carry it — never both, or the same
                // index ships twice (measured: ≈ 942 duplicated tokens per pass). Effort is not re-checked here:
                // this block IS the max-effort lever, gated above by App\Enums\ReasoningEffort; below the family's
                // top level the lever is silent and the tool behaves exactly as it always has.
                $hitsStats = $this->toolExecutionService->getLastEvidenceCallStats();
                $indexInHits = ($hitsStats['kind'] ?? '') === 'search' && !empty($hitsStats['map']);
                $ledger = $indexInHits ? '' : (string) $this->toolExecutionService->executeToolByName('search_session_evidence', [], $sessionId, $quiet);

                // The trace names the actor for every step (execution-trace spec, R1/R2) — these loads are the
                // app's own deterministic work, so they say so and carry their numbers.
                $hitCount = (int)preg_match_all('/\(score /', (string)$hits);
                $emit('trace', ['label' => $hitCount === 0
                    ? ($indexInHits
                        ? 'App: searched the stored passages (BM25) — no phrase match, the contents index is handed over instead'
                        : 'App: searched the stored passages (BM25) — no phrase match')
                    : sprintf('App: searched the stored passages (BM25) — %d passage%s matched%s',
                        $hitCount, $hitCount === 1 ? '' : 's', $indexInHits ? ', the contents index rode along' : '')]);
                if (!$indexInHits && trim($ledger) !== '') {
                    $unitCount = (int)preg_match_all('/\[S\d+-C\d+\]/', $ledger);
                    $emit('trace', ['label' => sprintf('App: supplied the contents index — %d unit%s, ≈ %s tokens',
                        $unitCount, $unitCount === 1 ? '' : 's', number_format((int)($this->countTokens)($ledger)))]);
                }

                foreach ([$hits, $ledger] as $forced) {
                    $forced = (string)$forced;
                    if (trim($forced) === '') {
                        continue;
                    }
                    // Both routes: as transient evidence (so every round's rebuild keeps it) and appended to this turn's
                    // first-pass array (which is built from history and would not otherwise carry it yet).
                    $transientEvidence[] = $forced;
                    $block = $this->promptAssemblyService->buildEvidenceBlock($forced);
                    if (($block['content'] ?? '') !== '') {
                        $currentMessages[] = $block;
                    }
                }
                \App\Logger::logEvent('forced_browse', 'Max reasoning: BM25 hits and the contents index loaded before the first pass', [
                    'session_id' => $sessionId,
                    'effort' => $reasoningEffort,
                    'browse_at' => $browseAt,
                    'hits_chars' => mb_strlen((string)$hits),
                    'ledger_chars' => mb_strlen((string)$ledger),
                    'index_in_search' => $indexInHits ? 1 : 0,
                ], 'info', 'ChatManager::processLocked');
            } catch (\Throwable $e) {
                // An enhancement must never cost a turn its answer.
                $emit('trace', ['label' => 'App: could not load the browse material — ' . $e->getMessage()]);
                \App\Logger::logEvent('forced_browse_failed', 'Could not load the forced browse material', [
                    'session_id' => $sessionId, 'error' => $e->getMessage(),
                ], 'warn', 'ChatManager::processLocked');
            }
        }

        // ── Queue 1: the loop queue (App\Services\LoopQueue) ─────────────────────────────────────────────
        // Opened BEFORE the first pass, because the pass that asks for tools is what appends the first round — the
        // queue has to exist by then. One queue per conversation: a second turn cannot open while one owns it.
        $loopQueue = \App\Services\LoopQueue::open($sessionId);
        if ($loopQueue === null) {
            \App\Logger::logEvent('loop_queue_open_refused', 'A turn already owns this conversation', [
                'session_id' => $sessionId,
            ], 'warn', 'ChatManager::processLocked');
        }

        $emit('status', ['text' => 'Analyzing your request…']);
        $passCount = 0;
        $first = $this->firstPass($currentMessages, $emit, $isEditorMode, $reasoningMode, $reasoningEffort, $unwatchedVideo !== '');
        $passCount++;
        $emit('trace', ['label' => self::passTraceLabel($passCount, (array)($first['tool_calls'] ?? []))]);

        // Driving the loop with the queue: the queue only learns about a round from the pass that asked for one, so the
        // append happens right here and at the bottom of the loop body — never at the loop's top, where the loop would
        // have to guess. The condition below then asks the queue what is owed.
        if ($loopQueue !== null && !empty($first['tool_calls'])) {
            $loopQueue->appendToolRound($first['tool_calls']);
        }

        // The stop control ends the turn here: no tool calls, no further inference, nothing left half-written. What the
        // pass already produced is kept and the fixed line is appended to it below, so history explains itself instead
        // of showing a fragment that looks like a crash (plan R8/R15). Reading the flag consumes it — one stop ends
        // exactly one turn — and the flag is only set by the stop control, never by a client that walked away (R14).
        $stoppedByUser = $this->stopRequested($sessionId);
        if ($stoppedByUser) {
            $first['tool_calls'] = [];
        }

        // ── the tool loop ───────────────────────────────────────────────────────────────
        // A turn may take several tool rounds: the model asks, the results are appended at the tail, and
        // the model gets to ask again before it answers. That is the difference between a model that can
        // only guess which passage holds the answer, and one that reads the list of passages and then
        // reads the right one. Each round is append-only (fresh rows are this turn's rich evidence,
        // transient retrieval goes after them, nothing already in the prompt moves), so the engine keeps
        // reusing the prefix it already cached between rounds.
        $freshRowIds = [];
        $toolRounds = 0;
        $loopRounds = 0;
        $forcedAnswer = false;
        $reminderRowId = 0;

        while ($loopQueue !== null ? $loopQueue->next() === \App\Services\LoopQueue::NEXT_ROUND : !empty($first['tool_calls'])) {
            // A stop can arrive while a round is running, so the flag is re-read at the round boundary: without this
            // the loop keeps asking the model for more, and the answer pass below still runs (measured: a stop pressed
            // during a search was followed by another inference). Reading consumes the flag — one stop, one turn.
            if (!$stoppedByUser && $this->stopRequested($sessionId)) {
                $stoppedByUser = true;
                // Shadow: the stop drops the rounds that have not run, which is what `break` does to the loop.
                $dropped = $loopQueue?->requestStop() ?? 0;
                $emit('trace', ['label' => sprintf('App: stop requested — the loop ends at this round boundary (%d un-run round%s dropped)',
                    $dropped, $dropped === 1 ? '' : 's')]);
                \App\Logger::logEvent('loop_queue_stop', 'Stop at a round boundary', [
                    'session_id' => $sessionId, 'loop_round' => $toolRounds, 'dropped_rounds' => $dropped,
                ], 'info', 'ChatManager::processLocked');
                break;
            }

            $loopRounds++;
            if ($loopQueue !== null) {
                // The round is already in the queue (the pass that asked for it appended it), so the loop consumes it
                // here and keeps its counter derived. A warn means the loop entered without the queue owing a round —
                // the one thing that must never happen, because it would mean two definitions of "a round is due".
                $toolRounds = $loopQueue->toolRounds();
                $verdict = (string) ($loopQueue->next() ?? 'done');
                \App\Logger::logEvent('loop_queue_round', 'Loop queue: running an owed round', [
                    'session_id' => $sessionId,
                    'loop_round' => $loopRounds,
                    'queue_rounds' => $toolRounds,
                    'queue_next' => $verdict,
                    'queue_pending' => $loopQueue->pendingRounds(),
                    'tools_closed' => $loopQueue->toolsClosed() ? 1 : 0,
                ], $verdict === \App\Services\LoopQueue::NEXT_ROUND ? 'info' : 'warn', 'ChatManager::processLocked');
                if ($verdict === \App\Services\LoopQueue::NEXT_ROUND) {
                    $loopQueue->markRoundDone();
                }
            } else {
                $toolRounds++;
            }
            \App\ProgressWriter::init('/tmp');
            $emit('status', ['text' => $toolRounds === 1 ? 'Executing tools...' : 'Reading what it found...']);
            $emit('trace', ['label' => sprintf('App: round %d of %d — running the model\'s %s',
                $loopRounds, self::MAX_TOOL_ROUNDS, count($first['tool_calls']) === 1 ? 'call' : 'calls')]);

            foreach ($first['tool_calls'] as $toolCall) {
                $fn = $toolCall['function'] ?? [];
                $toolName = $fn['name'] ?? '';
                $argsJson = $fn['arguments'] ?? '{}';
                $args = json_decode($argsJson, true) ?: [];
                // A model that emits `queries` as a string used to reach implode() below and throw a
                // TypeError, which killed the stream before its terminal event. Normalise at the boundary
                // so the guard holds for every caller further down (ToolExecutionService, the tools).
                $args = \App\Services\ToolExecutionService::normalizeToolArguments($args, (string) ($fn['name'] ?? ''));
                $queries = $args['queries'] ?? [];

                if (empty($toolName)) {
                    \App\Logger::logEvent('llm_tool_no_match', 'LLM tool_call had no function name', [
                        'reason' => 'empty_name',
                    ], 'warn', 'ChatManager::processLocked');
                    continue;
                }
                // This tool is the deliberate exception: its whole point is that a call may carry nothing
                // at all (see what the stored sources contain), or only passage ids. Requiring arguments
                // here would discard exactly the call the model is invited to make.
                if (!\App\Services\ToolExecutionService::hasExecutableArguments($toolName, $args)) {
                    \App\Logger::logEvent('llm_tool_no_match', 'LLM tool_call had no executable arguments', [
                        'reason' => 'empty_args',
                        'tool_name' => $toolName,
                    ], 'warn', 'ChatManager::processLocked');
                    continue;
                }

                // A browsing call has no queries to name it, so the label comes from the passages asked for
                // (or says it listed the sources) — the reader of the transcript sees what it did.
                if ($toolName === 'create_calendar_task') {
                    $queryList = (string)($args['content'] ?? '');
                } elseif ($toolName === 'search_session_evidence') {
                    $wanted = $args['ids'] ?? ($args['chunk_ids'] ?? []);
                    $wanted = is_array($wanted) ? array_values(array_filter(array_map('strval', $wanted))) : [(string)$wanted];
                    if ($wanted !== []) {
                        $queryList = implode(', ', $wanted);
                    } elseif (!empty($queries)) {
                        $queryList = implode(', ', $queries);
                    } else {
                        $queryList = 'the contents index';
                    }
                } else {
                    $queryList = implode(', ', $queries);
                }
                $emit('tool_start', ['tool' => $toolName, 'label' => "{$toolName}: {$queryList}"]);

                $result = $this->toolExecutionService->executeToolByName(
                    $toolName, $args, $sessionId, $emit
                );

                // search_session_evidence is TRANSIENT: its rehydrated chunks are
                // injected for this turn only. Do NOT persist a durable Context Data
                // row, do NOT promote retrieved chunks to atomic_context, and do NOT
                // allocate new source IDs (chunks keep their original S#-C# refs).
                if ($toolName === 'search_session_evidence') {
                    $transientEvidence[] = $result;
                    $transientSourceIds = array_merge(
                        $transientSourceIds,
                        $this->toolExecutionService->getLastRetrievedSourceIds()
                    );
                    $emit('tool_done', ['tool' => $toolName, 'label' => "{$toolName} completed."]);
                    $emit('trace', ['label' => $this->evidenceReturnLabel($toolName, (string)$result)]);
                    continue;
                }

                $lastSourceMap = $this->toolExecutionService->getLastSourceMap();
                $lastBackingChunks = $this->toolExecutionService->getLastBackingChunks();
                $lastSelectedChunks = $this->toolExecutionService->getLastSelectedChunks();
                $lastFailures = $this->toolExecutionService->getLastFailures();
                $sourceMap = array_merge($sourceMap, $lastSourceMap);
                $failedSources = array_merge($failedSources, $lastFailures);
                // The panel's successes and failures are both derived from the evidence this turn produced: a
                // source in the map with no block in here is one the answer never saw. Passing anything else
                // (the answer text, an empty string) reports every fetched page as unreadable.
                $turnEvidence .= $result;

                $emit('tool_done', ['tool' => $toolName, 'label' => "{$toolName} completed."]);
                $emit('trace', ['label' => $this->evidenceReturnLabel($toolName, (string)$result)]);

                // Raw evidence is stored un-atomized (atomic_context null) with
                // the exact selected chunks persisted so a later deferred
                // atomization pass can condense this row on its own. The next
                // turn injects raw until that happens.
                $rowFailures = \App\Search\SourceFailures::finalize($lastFailures, $lastSourceMap, $result);

                $this->db->insert('chat_history', [
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'message' => $result,
                    'message_type' => 'data_fetching',
                    'tool_name' => $toolName,
                    'search_query' => $queryList,
                    'source_map' => !empty($lastSourceMap) ? json_encode($lastSourceMap) : null,
                    'backing_chunks' => !empty($lastBackingChunks) ? json_encode($lastBackingChunks, JSON_UNESCAPED_UNICODE) : null,
                    'selected_chunks' => ($toolName === 'search_web' && !empty($lastSelectedChunks)) ? json_encode($lastSelectedChunks, JSON_UNESCAPED_UNICODE) : null,
                    'failed_sources' => $rowFailures ? json_encode($rowFailures, JSON_UNESCAPED_UNICODE) : null,
                    'atomic_context' => null,
                    'token_estimate' => ($this->countTokens)($result),
                ]);

                $historyId = (int) $this->db->getConnection()->lastInsertId();
                $freshRowIds[] = $historyId;
                if (!empty($lastSelectedChunks) || !empty($lastBackingChunks)) {
                    $this->turnFetchRowIds[] = $historyId;
                }

                $emit('context_data_added', [
                    'id' => $historyId,
                    'label' => $queryList,
                    'tool_name' => $toolName,
                    'query' => $queryList,
                    'source_count' => count($lastSourceMap),
                    'token_estimate' => ($this->countTokens)($result),
                    'active' => true,
                ]);
            }

            $createdTasks = $this->toolExecutionService->getCreatedTasks();
            if (!empty($createdTasks)) {
                $emit('calendar_task_created', [
                    'count' => count($createdTasks),
                    'tasks' => $createdTasks,
                ]);
                $turnEvidence .= $result;
            }

            \App\ProgressWriter::done($sessionId);

            // Everything this round produced, re-assembled positionally: the fresh rows are this turn's
            // rich evidence (atoms replace them from the next turn on), transient retrieval is appended
            // after them, and nothing already in the prompt moves. Append-only is what keeps the cached
            // prefix reusable between rounds.
            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $freshRowIds, $currentTime);
            foreach ($transientEvidence as $te) {
                $block = $this->promptAssemblyService->buildEvidenceBlock($te);
                if (($block['content'] ?? '') !== '') {
                    $currentMessages[] = $block;
                }
            }

            if ($toolRounds >= self::MAX_TOOL_ROUNDS) {
                // Shadow: the queue closed its tools when the last round was appended. A disagreement here would mean
                // the two define "the cap is reached" differently.
                if ($loopQueue !== null && !$loopQueue->toolsClosed()) {
                    \App\Logger::logEvent('loop_queue_mismatch', 'Loop hit the round cap but the queue still offers tools', [
                        'session_id' => $sessionId, 'loop_round' => $toolRounds,
                        'queue_rounds' => $loopQueue->toolRounds(),
                    ], 'warn', 'ChatManager::processLocked');
                }
                // Cap reached: stop offering tools and require the answer from what has been gathered —
                // the terminal state the single-round design always had, kept as the net for a model that
                // would otherwise keep asking.
                $emit('trace', ['label' => sprintf('App: round cap (%d) reached — tools closed, the answer must come from what is gathered', self::MAX_TOOL_ROUNDS)]);
                \App\Logger::logEvent('tool_round_cap', 'Tool round cap reached; answering from what was gathered', [
                    'rounds' => $toolRounds,
                ], 'warn', 'ChatManager::processLocked');

                $reminderText = self::runtimeReminder($query);
                $reminderRowId = (int) ($freshRowIds[count($freshRowIds) - 1] ?? 0);
                if ($reminderRowId > 0) {
                    // Stored on the row, so the assembler emits it right after that row in every later
                    // prompt too; the messages are rebuilt so this turn's answer sees it as well.
                    $this->db->update('chat_history', ['turn_reminder' => $reminderText], ['id' => $reminderRowId]);
                    $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
                    $currentMessages = $this->promptAssemblyService->buildMessagesArray($systemPrompt, $history, $freshRowIds, $currentTime);
                    foreach ($transientEvidence as $te) {
                        $block = $this->promptAssemblyService->buildEvidenceBlock($te);
                        if (($block['content'] ?? '') !== '') {
                            $currentMessages[] = $block;
                        }
                    }
                } else {
                    // No durable row to carry it, so it stays inline for this call.
                    $currentMessages[] = ['role' => 'user', 'content' => $reminderText];
                }

                $forcedAnswer = true;
                break;
            }

            // Another look is allowed: the model has just read something it asked for, and asking again
            // from what came back is how a list of passages becomes the passage that holds the answer.
            $emit('status', ['text' => 'Checking what it found...']);
            $first = $this->firstPass($currentMessages, $emit, $isEditorMode, $reasoningMode, $reasoningEffort, $unwatchedVideo !== '');
            $passCount++;
            $emit('trace', ['label' => self::passTraceLabel($passCount, (array)($first['tool_calls'] ?? []))]);

            // The pass asked for tools again: that request is the queue's next round, and the loop's condition picks it
            // up. If the queue refuses (tools closed at the cap, or the turn was stopped), nothing is appended and the
            // loop ends — the answer pass is owed instead.
            if ($loopQueue !== null && !empty($first['tool_calls'])) {
                $loopQueue->appendToolRound($first['tool_calls']);
            }
        }

        // The reminder is still kept on the turn's last evidence row after a natural finish, so later
        // prompts re-emit it in the same position. It is not part of this answer; it is part of the next
        // prompt's stable prefix.
        if ($loopQueue !== null) {
            // The turn's verdict, computed by the queue and compared with what the loop is about to do: a stopped turn
            // owes nothing more, any other turn owes the answer pass. This is the line the driving step will read.
            $verdict = (string) ($loopQueue->next() ?? 'done');
            $expected = $stoppedByUser ? 'done' : \App\Services\LoopQueue::NEXT_ANSWER;
            \App\Logger::logEvent('loop_queue_verdict', 'Loop queue verdict for this turn', [
                'session_id' => $sessionId,
                'rounds' => $toolRounds,
                'forced_answer' => $forcedAnswer ? 1 : 0,
                'queue_next' => $verdict,
                'expected' => $expected,
                'queue_pending' => $loopQueue->pendingRounds(),
                'stopped' => $stoppedByUser ? 1 : 0,
            ], $verdict === $expected ? 'info' : 'warn', 'ChatManager::processLocked');
        }

        if ($toolRounds > 0 && !$forcedAnswer && $reminderRowId === 0) {
            $reminderRowId = (int) ($freshRowIds[count($freshRowIds) - 1] ?? 0);
            if ($reminderRowId > 0) {
                $this->db->update('chat_history', ['turn_reminder' => self::runtimeReminder($query)], ['id' => $reminderRowId]);
            }
        }

        // The panel's two groups are computed once and reused for the live event and the stored row, so a reload
        // cannot disagree with what the turn displayed. Successes exclude anything listed as failed: the map
        // holds everything that was fetched, including pages that were read and contributed nothing.
        $panelFailures = \App\Search\SourceFailures::finalize($failedSources, $sourceMap, $turnEvidence);
        if (!empty($sourceMap) || !empty($panelFailures)) {
            // Successes and failures travel together so the panel can show both groups from one event, and
            // the sources the user never saw are named rather than silently absent (P4-S2).
            $emit('sources', [
                'sources' => \App\Search\SourceFailures::used($sourceMap, $panelFailures),
                'failed'  => $panelFailures,
            ]);
        }

        $emit('generating', []);

        // The last chance to notice a stop, for one that arrived while the tools were being read.
        if (!$stoppedByUser) {
            $stoppedByUser = $this->stopRequested($sessionId);
        }

        if ($stoppedByUser) {
            // Stopped: keep what the pass wrote, run nothing else. (The line is appended below.)
            $aiRawResponse = (string)($first['content'] ?? '');
        } elseif ($forcedAnswer) {
            // The loop ran out of rounds: generate the answer now, with the tools in view but not offered
            // (tool_choice none) — the terminal state the single-round design always had.
            $aiRawResponse = $this->streamAgentResponse($currentMessages, $emit, $reasoningMode, $reasoningEffort, $this->buildToolSchemas($isEditorMode, $unwatchedVideo !== ''));
        } else {
            // The last pass already streamed the answer live — whether it was the only pass, or the last
            // of several tool rounds.
            $aiRawResponse = $first['content'] ?? '';

            // Empty-answer guard: a deep-thinking prompt (e.g. a riddle) can burn
            // the whole budget on reasoning and emit zero content. Retry once in
            // instruct mode (reasoning off) so the user gets a direct answer
            // instead of an empty bubble after a long thinking phase.
            if ($aiRawResponse === '' && $this->agent->lastReasoningChars > 0) {
                \App\Logger::logEvent('llm_empty_answer_firstpass', 'First pass produced reasoning with zero content; retrying once in instruct mode', [
                    'finish_reason' => $first['finish_reason'] ?? null,
                    'reasoning_chars' => $this->agent->lastReasoningChars,
                ], 'error', 'ChatManager::handleChat');
                $aiRawResponse = $this->retryAnswerInstruct($currentMessages, $emit, $this->resolveAnswerMaxTokens($currentMessages));
            }
        }
        $finalResponse = $aiRawResponse;

        $visibleSourceIds = $this->promptAssemblyService->extractVisibleSourceIds($history);
        if (!empty($transientSourceIds)) {
            $visibleSourceIds = array_values(array_unique(array_merge($visibleSourceIds, $transientSourceIds)));
        }
        $cleanResponse = (new CitationValidator())
            ->sanitizeCitations($finalResponse, $visibleSourceIds);

        if ($stoppedByUser) {
            // The line the owner specified, appended on its own line as if the model had said it, so the transcript
            // explains the ending instead of looking truncated (plan R15). It survives a reload because it is part of
            // the stored message.
            $cleanResponse = self::withStopNotice($cleanResponse);
            \App\Logger::logEvent('turn_stopped_by_user', 'Turn ended by the stop control', [
                'session_id' => $sessionId,
                'kept_chars' => mb_strlen($finalResponse),
                'tool_rounds' => $toolRounds,
            ], 'info', 'ChatManager::processLocked');
        }

        $usage = $this->agent->lastUsage;
        $assistantTokens = (int)(mb_strlen($cleanResponse) / 4);

        if ($usage) {
            if (isset($usage['prompt_tokens'])) {
                $userTokens = (int)$usage['prompt_tokens'];
                $userHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId, 'role' => 'user']);
                if (!empty($userHistory)) {
                    usort($userHistory, fn($a, $b) => $b['id'] - $a['id']);
                    $this->db->update('chat_history', [
                        'token_estimate' => $userTokens
                    ], ['id' => $userHistory[0]['id']]);
                }
            }
            if (isset($usage['completion_tokens'])) {
                $assistantTokens = (int)$usage['completion_tokens'];
            }
        }

        // The answer and its terminal link are written together. If another runner already closed this turn, the link
        // is refused and the answer is rolled back with it — a doubled answer leaves no stored response, and the row
        // it answered stays owed rather than half-closed.
        $conn = $this->db->getConnection();
        $conn->beginTransaction();
        try {
            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role' => 'assistant',
                'message' => $cleanResponse,
                'image_path' => null,
                'token_estimate' => $assistantTokens,
                'search_query' => null,
                'source_map' => empty($sourceMap) ? null : json_encode($sourceMap),
                // Stored so a reload renders the same two groups without re-deriving them from evidence it no
                // longer has (the assistant row holds the answer, never the evidence blocks).
                'failed_sources' => empty($panelFailures) ? null : json_encode($panelFailures, JSON_UNESCAPED_UNICODE),
                'model' => $this->agent->getModelName(),
                'had_tool_calls' => $toolRounds > 0 ? 1 : 0,
            ]);

            $assistantRowId = (int) $conn->lastInsertId();

            if (!(new \App\Services\TurnAnswers($this->db))->record($terminalUserRowIds, $assistantRowId)) {
                $conn->rollBack();

                \App\Logger::logEvent('turn_answer_refused', 'This turn was already closed; the duplicate answer was not stored', [
                    'session_id' => $sessionId,
                    'user_rows' => $terminalUserRowIds,
                    'assistant_row_id' => $assistantRowId,
                ], 'warn', 'ChatManager::processLocked');

                $emit('trace', ['label' => 'App: this turn was already answered — the duplicate answer was discarded']);
                $emit('done', [
                    'message' => $cleanResponse,
                    'title' => $updatedTitle,
                    'session_id' => $sessionId,
                    'message_id' => null,
                    'had_tool_calls' => !empty($first['tool_calls']),
                    'sources' => $sourceMap,
                ]);

                return [
                    'status' => 'success',
                    'message' => $cleanResponse,
                    'title' => $updatedTitle,
                    'meta' => ['search_triggered' => false, 'search_query' => null],
                ];
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        // The batch the answer belonged to becomes ordinary conversation now: those rows were held out of the prompt
        // while they waited, and from here on they are messages like any other. Done only once the answer is stored, so
        // a turn that dies mid-way leaves them held rather than half-adopted.
        if ($adoptedRowIds !== []) {
            $adoptedRowIds = array_values(array_unique(array_map('intval', $adoptedRowIds)));
            $placeholders = implode(',', array_fill(0, count($adoptedRowIds), '?'));
            $this->db->query(
                "UPDATE chat_history SET message_type = 'text' WHERE session_id = ? AND message_type = 'queued'"
                . " AND id IN ({$placeholders})",
                array_merge([$sessionId], $adoptedRowIds)
            );
            \App\Logger::logEvent('batch_answered', 'Held messages answered and released into the conversation', [
                'rows' => $adoptedRowIds,
                'assistant_row_id' => $assistantRowId,
            ], 'info', 'ChatManager::processLocked');
        }

        // Per-turn performance metrics: total wall-clock + TTFT + the agent's
        // per-call log (firstpass / answer / condenser / ...). Persisted on the
        // assistant row so every bubble carries its own metrics on reload.
        $perfMetrics = [
            'total_ms' => (int) round((microtime(true) - $turnStart) * 1000),
            'ttft_ms' => $firstTokenTs !== null ? (int) round(($firstTokenTs - $turnStart) * 1000) : null,
            'calls' => $this->agent->callLog,
            // Reasoning as the backend resolved and applied it. 'requested' is
            // what arrived with the request, kept only so a divergence between
            // the two is visible; the resolved/applied pair is the truth.
            'reasoning' => [
                'requested' => $effort,
                'mode' => $reasoningMode,
                'effort' => $reasoningEffort,
            ],
        ];
        $this->db->update('chat_history', [
            'perf_metrics' => json_encode($perfMetrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id' => $assistantRowId]);

        // Persist the full per-turn metrics (wall clock, TTFT, per-call timings)
        // into the event log so the session timeline can display them.
        \App\Logger::logEvent('turn_metrics', "Turn metrics: {$perfMetrics['total_ms']}ms total, " . count($perfMetrics['calls']) . ' calls', [
            'total_ms' => $perfMetrics['total_ms'],
            'ttft_ms' => $perfMetrics['ttft_ms'],
            'calls' => $perfMetrics['calls'],
            'reasoning' => $perfMetrics['reasoning'],
            'assistant_row_id' => $assistantRowId,
        ], 'info', 'ChatManager::processLocked');

        $finalHistory = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        if ($usage && isset($usage['prompt_tokens'])) {
            $totalSessionTokens = (int)$usage['prompt_tokens'];
        } else {
            $totalSessionTokens = 0;
            foreach ($finalHistory as $row) {
                $totalSessionTokens += (int)($row['token_estimate'] ?? 0);
            }
        }

        $this->db->update('chat_sessions', [
            'context_tokens' => $totalSessionTokens
        ], ['id' => $sessionId]);

        // No post-answer consolidation here. Atomization is deferred: the raw
        // evidence stays active this turn, and a later turn's
        // atomizeBacklogIfNeeded() pass condenses it once the backlog (or
        // context pressure) justifies the cost. The answer is never blocked.

        \App\Logger::logEvent('stream_complete', 'Final response delivered to client', [
            'response_chars' => mb_strlen($cleanResponse),
            'total_ms' => $perfMetrics['total_ms'],
            'ttft_ms' => $perfMetrics['ttft_ms'],
            'calls' => count($perfMetrics['calls']),
            'sources' => count($sourceMap),
            'total_session_tokens' => $totalSessionTokens,
        ], 'info', 'ChatManager::processLocked');

        $emit('trace', ['label' => sprintf('App: turn complete — %d of %d rounds used, %d model pass%s, %.1f s',
            $toolRounds, self::MAX_TOOL_ROUNDS, $passCount, $passCount === 1 ? '' : 'es', ((int)($perfMetrics['total_ms'] ?? 0)) / 1000)]);

        $emit('done', [
            'message' => $cleanResponse,
            'title' => $updatedTitle,
            'total_session_tokens' => $totalSessionTokens,
            'session_id' => $sessionId,
            'message_id' => $assistantRowId,
            'had_tool_calls' => !empty($first['tool_calls']),
            'sources' => $sourceMap,
            'perf_metrics' => $perfMetrics,
            'content_hash' => hash_final($contentHashCtx),
            'content_length' => $contentBytes,
        ]);

        return [
            'status' => 'success',
            'message' => $finalResponse,
            'title' => $updatedTitle,
            'meta' => [
                'search_triggered' => false,
                'search_query' => null,
            ]
        ];
    }

    /**
     * Write the user row for a turn — attachment upload included — and return its id and stored image path.
     *
     * One implementation for both callers: a request that is about to queue the turn (so the job can carry the row id)
     * and this class when it is the thing running the turn. The worker's turn body adopts the row, which is why the row
     * must exist before the job does — "a turn is owed" is a fact about rows, never about jobs.
     *
     * @return array{row_id:int,image_path:?string}
     */
    public function persistUserTurn(int $sessionId, string $query, ?array $imageFile, ?string $effort = null, ?string $editFile = null, ?array $fetchUrls = null): array
    {
        $imagePath = null;
        if ($imageFile && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $imagePath = $this->fileAttachmentService->handleUpload($sessionId, $imageFile);
        }

        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'user',
            'message' => $query,
            'image_path' => $imagePath,
            'time_note' => \App\Services\PromptAssemblyService::timeBucket(),
            'token_estimate' => (int)(mb_strlen($query) / 4),
            // The per-submit choices, as facts about this message (see Schema.php): the row is the record, so a later
            // consumer of this turn reads them here instead of receiving them through the job.
            'turn_effort' => $effort,
            'turn_edit_file' => $editFile,
            'turn_fetch_urls' => $fetchUrls === null ? null : json_encode(array_values($fetchUrls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $rowId = (int)$this->db->getConnection()->lastInsertId();

        \App\Logger::logEvent('user_message_persisted', 'User message stored', [
            'query_chars' => mb_strlen($query),
            'history_row_id' => $rowId,
            'has_image' => $imagePath !== null,
            'effort' => $effort,
            'edit_file' => $editFile !== null && $editFile !== '' ? basename($editFile) : null,
            'links' => $fetchUrls === null ? 'rule' : (count($fetchUrls) === 0 ? 'off' : count($fetchUrls) . ' url(s)'),
        ], 'info', 'ChatManager::persistUserTurn');

        return ['row_id' => $rowId, 'image_path' => $imagePath];
    }

    /**
     * The per-submit choices this turn runs with, resolved in one place.
     *
     * Order: what the caller passed explicitly (an in-process caller, a test, an API path), else what the user's own row
     * recorded when the message was written, else the ordinary default. Nothing may invent a value the row holds — that
     * is how a held message used to be answered at `medium` with the normal tool surface when the user had sent it from
     * the editor at high effort.
     *
     * A batch of adopted rows takes the **newest** row's choices: it is the last thing the user said, and the batch is
     * answered as one turn.
     *
     * @return array{effort:string,edit_file:?string,fetch_urls:?array<int,string>,source:array<string,string>}
     */
    private function resolveTurnChoices(array $adoptedRowIds, ?string $effort, ?string $editFile, ?array $fetchUrls): array
    {
        $row = ['turn_effort' => null, 'turn_edit_file' => null, 'turn_fetch_urls' => null];
        if ($adoptedRowIds !== []) {
            $ids = array_map('intval', $adoptedRowIds);
            $rows = $this->db->query(
                'SELECT turn_effort, turn_edit_file, turn_fetch_urls FROM chat_history WHERE id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 1',
                $ids
            );
            if ($rows !== []) {
                $row = array_merge($row, $rows[0]);
            }
        }

        $source = [];

        if ($effort !== null && $effort !== '') {
            $source['effort'] = 'caller';
        } elseif (!empty($row['turn_effort'])) {
            $effort = (string)$row['turn_effort'];
            $source['effort'] = 'row';
        } else {
            $effort = 'medium';
            $source['effort'] = 'default';
        }

        if ($editFile !== null && $editFile !== '') {
            $source['edit_file'] = 'caller';
        } elseif (!empty($row['turn_edit_file'])) {
            $editFile = (string)$row['turn_edit_file'];
            $source['edit_file'] = 'row';
        } else {
            $editFile = null;
            $source['edit_file'] = 'default';
        }

        if ($fetchUrls !== null) {
            $source['fetch_urls'] = 'caller';
        } elseif ($row['turn_fetch_urls'] !== null && $row['turn_fetch_urls'] !== '') {
            $decoded = json_decode((string)$row['turn_fetch_urls'], true);
            $fetchUrls = is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
            $source['fetch_urls'] = 'row';
        } else {
            // null, not []: the app's own link rule runs at turn time. An empty list would mean "open nothing, do not
            // even scan the text" — the user's explicit choice, and not the default. The source is named for what
            // actually happens rather than for the absence of a value.
            $fetchUrls = null;
            $source['fetch_urls'] = 'rule';
        }

        return ['effort' => $effort, 'edit_file' => $editFile, 'fetch_urls' => $fetchUrls, 'source' => $source];
    }

    private function preflightContext(int $sessionId, string $query, bool $isEditorMode): ?array
    {
        $ctxSize = (int) Config::get('LLM_CTX_SIZE', 0);
        if ($ctxSize <= 0) {
            return null;
        }

        $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
        $systemPrompt = $this->promptAssemblyService->buildSystemPrompt($isEditorMode);
        $breakdown = $this->promptAssemblyService->estimatePromptTokens($systemPrompt, $history, $query);

        if (!PromptAssemblyService::projectsOverflow($breakdown, self::OUTPUT_RESERVE_TOKENS, $ctxSize, self::SAFETY_MARGIN_TOKENS)) {
            return null;
        }

        $total = $breakdown['total'] + self::OUTPUT_RESERVE_TOKENS + self::SAFETY_MARGIN_TOKENS;
        return [
            'total' => $total,
            'max' => $ctxSize,
            'output_reserve' => self::OUTPUT_RESERVE_TOKENS,
            'breakdown' => $breakdown,
            'message' => sprintf(
                'Context limit reached (%d/%d tokens). Context Data ~%d · Chat ~%d · output reserve %d. Evict raw context or condense chat to continue.',
                $total,
                $ctxSize,
                $breakdown['context_data'],
                $breakdown['recent_chat'],
                self::OUTPUT_RESERVE_TOKENS
            ),
        ];
    }

    private function ensureSessionExists(int $sessionId): void
    {
        // One place owns session creation (App\Repositories\ChatSessionRepository): the briefing
        // stream writes chat_history by hand and needs the same guarantee.
        (new \App\Repositories\ChatSessionRepository($this->db))->ensureExists($sessionId);
    }

    private function buildToolSchemas(bool $isEditorMode = false, bool $noSearch = false): array
    {
        $tools = $this->buildToolSchemasAll($isEditorMode);

        // A turn whose only source was a video that could not be read has nothing to search for: the video is
        // not on the web, and searching its address returned pages about the subject instead of the clip. The
        // answer says what happened rather than looking somewhere else.
        if ($noSearch) {
            $tools = array_values(array_filter($tools, static fn(array $tool): bool =>
                !in_array($tool['function']['name'] ?? '', ['search_web', 'search_local'], true)));
        }

        return $tools;
    }

    private function buildToolSchemasAll(bool $isEditorMode = false): array
    {
        if ($isEditorMode) {
            return [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_memories',
                        'description' => 'Search the user\'s long-term memories. Use when the user asks about saved facts, preferences, or anything remembered about them.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'queries' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'description' => 'Memory search queries. Include synonyms and alternate phrasings.',
                                ],
                            ],
                            'required' => ['queries'],
                        ],
                    ],
                ],
            ];
        }

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_local',
                    'description' => 'Search the user\'s own local files and long-term memories. Use when the user asks about their personal data, documents, or saved facts.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Search queries. Include synonyms and alternate phrasings.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_web',
                    'description' => self::$legacyToolDescriptions
                        ? 'Search the web for current or factual information beyond the user\'s personal data. Use for recent events, news, prices, or anything you cannot answer confidently from knowledge. Include the current year in queries for time-sensitive topics.'
                        : 'Search the public web for current or factual information beyond the user\'s personal data. Use for recent events, news, prices, or information requiring new or independent sources. To investigate sources already fetched in this conversation, use search_session_evidence to reach their stored originals. Include the current year in queries for time-sensitive topics.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Focused web search queries. Keep it tight.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_calendar',
                    'description' => 'Read the user\'s calendar — their tasks and events. Use when the user asks what is already scheduled or on their list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Calendar search queries.',
                            ],
                        ],
                        'required' => ['queries'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_session_evidence',
                    'description' => self::$legacyToolDescriptions
                        ? 'Search the sources already fetched in this conversation (pages fetched earlier, cited as [S#]). Give it words and it returns the passages that match them — and, with them, a list of everything else that is stored, each passage described. So when the words you have do not match the passage you need, you can choose one by what it covers instead of giving up or searching the web. Read any passage in full by naming its id. When the user seems unsure if their question is answered by your reply, please trust the user and do a scan on the session evidence to see if you got the facts right.'
                        : 'Explore the original sources already fetched in this conversation. The source notes visible in the prompt are selective, model-derived condensations and may omit details or qualifications present in the originals. Search with queries to retrieve matching original passages and the contents index, or call with no arguments to browse the complete contents index by subject. Read a passage by naming its id, or a whole source by naming its source id. When the user asks for a closer look at one of these sources, or questions an earlier answer, read the stored original instead of relying on previous summaries.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'queries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => self::$legacyToolDescriptions
                                    ? 'Words to look for in the stored sources.'
                                    : 'Words or phrases to find in stored original text. Returns matching passages and the contents index.',
                            ],
                            'ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => self::$legacyToolDescriptions
                                    ? 'Passage ids to read in full, as listed in a previous result, e.g. ["S1-C6"].'
                                    : 'IDs to read verbatim. S1 reads the whole source; S1-C3 reads one passage.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_calendar_task',
                    'description' => 'Add a task or reminder to the user\'s calendar. Use when the user asks to add, schedule, note, or be reminded of something — not when they ask what already exists. The system automatically detects duplicate or overlapping tasks and reports them instead of creating twice, so create without checking first.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'Task content.',
                            ],
                            'due_string' => [
                                'type' => 'string',
                                'description' => 'Natural-language due date, e.g. "Sep 29 10:00".',
                            ],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculate',
                    'description' => 'Calculate a number or a date. Give an operation name and its values as strings, e.g. {"operation":"add","values":["0.10","0.20"]} or {"operation":"days_between","from":"2026-10-11","to":"2026-11-15"}. Operations: add, subtract, multiply, divide (values); percent_of, percent_change (value, percent); total (values, precision, rounding); days_between (from, to); date_add, date_subtract (date, days|weeks|months). Results are exact decimal strings — never estimate a sum, discount, total or date yourself, and never write your own formula: call this and use its result.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'operation' => [
                                'type' => 'string',
                                'description' => 'One of: add, subtract, multiply, divide, percent_of, percent_change, total, days_between, date_add, date_subtract.',
                            ],
                            'values' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Decimal strings, e.g. ["0.10","0.20"]. Between 2 and 20 of them.',
                            ],
                            'value' => ['type' => 'string', 'description' => 'One decimal string.'],
                            'percent' => ['type' => 'string', 'description' => 'A percentage as a decimal string, e.g. "20".'],
                            'direction' => ['type' => 'string', 'description' => 'increase or decrease — required for percent_change.'],
                            'precision' => ['type' => 'integer', 'description' => 'Decimal places, 0 to 12. Required for total.'],
                            'rounding' => ['type' => 'string', 'description' => 'half-up (total only).'],
                            'from' => ['type' => 'string', 'description' => 'ISO date Y-m-d (days_between).'],
                            'to' => ['type' => 'string', 'description' => 'ISO date Y-m-d (days_between).'],
                            'date' => ['type' => 'string', 'description' => 'ISO date Y-m-d (date_add, date_subtract).'],
                            'days' => ['type' => 'integer', 'description' => 'Whole days to shift (date_add, date_subtract).'],
                            'weeks' => ['type' => 'integer', 'description' => 'Whole weeks to shift (date_add, date_subtract).'],
                            'months' => ['type' => 'integer', 'description' => 'Whole months to shift, clamped at month end (date_add, date_subtract).'],
                            'timezone' => ['type' => 'string', 'description' => 'IANA timezone; defaults to the app clock.'],
                        ],
                        'required' => ['operation'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Whether this history is the session's first user turn — the moment a conversation earns its
     * name. Evidence rows (a pasted link, an attached file) are written into the same turn, so the
     * row count is not the first-turn signal; exactly one user row is.
     */
    public static function isFirstUserTurn(array $history): bool
    {
        $userRows = 0;
        foreach ($history as $row) {
            if (($row['role'] ?? '') === 'user') {
                $userRows++;
            }
        }
        return $userRows === 1;
    }

    /**
     * A conversation is named after the user's own words. A pasted URL is not a title, so bare links
     * are dropped and the rest of the message names the chat; a message that was nothing but a link
     * falls back to the link itself.
     */
    public static function titleFromQuery(string $query): string
    {
        $stripped = trim((string) preg_replace('~\s+~u', ' ', (string) preg_replace('~https?://\S+~iu', ' ', $query)));
        $source = $stripped !== '' ? $stripped : trim($query);

        return mb_strlen($source) > 60 ? mb_substr($source, 0, 57) . '...' : $source;
    }

    private function autoGenerateTitle(int $sessionId, string $query, array $history, callable $emit): ?string
    {
        if (!self::isFirstUserTurn($history)) return null;

        $title = self::titleFromQuery($query);

        $this->db->update('chat_sessions', ['title' => $title], ['id' => $sessionId]);
        $emit('title_updated', ['title' => $title]);
        return $title;
    }

    /** stored ∈ {off, low, medium, high} → [mode, effort] for the thinking passes. */
    /**
     * The effort at which the forced contents browse engages — derived from the published policy, through the enums:
     *   - a GRADUATED family (non-empty `effort_map`, declared per family in models.json) is at its maximum at the top
     *     of its own ladder — for the qwen family that is `high`, which its template renders as xhigh;
     *   - a BINARY family (no `effort_map`) is at its maximum as soon as thinking is on — the literal `on`.
     * Returns null when the policy says nothing about reasoning; nothing is forced then.
     */
    private function forcedBrowseAt(): ?string
    {
        $policy = json_decode((string) \App\Config::get('LLM_RUNTIME_POLICY', '{}'), true) ?: [];
        $map = $policy['reasoning']['effort_map'] ?? [];
        if (!is_array($map) || $map === []) {
            return 'on';
        }

        $top = null;
        foreach (array_keys($map) as $level) {
            $candidate = \App\Enums\ReasoningEffort::tryFrom((string)$level);
            if ($candidate === null) {
                continue;   // a level the vocabulary does not know is ignored — never invented, never guessed at
            }
            if ($top === null || $candidate->rank() > $top->rank()) {
                $top = $candidate;
            }
        }

        return $top?->value;
    }

    /** The model's own word for a tool in the trace — schema names are internals, not prose (spec R6). */
    private static function toolTraceName(string $tool): string
    {
        return match ($tool) {
            'search_session_evidence' => 'stored evidence',
            'search_web' => 'the web',
            'search_local' => 'files & memories',
            'search_memories' => 'memories',
            'search_calendar' => 'the calendar',
            'create_calendar_task' => 'a calendar task',
            'calculate' => 'a calculation',
            default => $tool,
        };
    }

    /** One trace line per model pass: what it asked for, or that it answered (spec, section C). */
    private static function passTraceLabel(int $pass, array $toolCalls): string
    {
        if ($toolCalls === []) {
            return sprintf('Model: pass %d — answered directly', $pass);
        }
        $names = [];
        foreach ($toolCalls as $call) {
            $names[] = self::toolTraceName((string)($call['function']['name'] ?? ''));
        }

        return sprintf('Model: pass %d — asked for tools: %s', $pass, implode(', ', array_unique($names)));
    }

    /**
     * What a stored-evidence call actually loaded. "Returned 2.9k chars" cannot tell a full load from a
     * fragment; units read vs available, anything withheld, and chars ≈ tokens can — the trace carries them.
     */
    private function evidenceReturnLabel(string $toolName, string $result): string
    {
        $stats = $toolName === 'search_session_evidence' ? $this->toolExecutionService->getLastEvidenceCallStats() : [];
        $kind = (string)($stats['kind'] ?? '');
        $tokens = is_callable($this->countTokens) ? (int)($this->countTokens)($result) : (int)ceil(mb_strlen($result) / 4);

        if ($kind === 'read') {
            $label = sprintf('App: read %d of %s units (%s) — ≈ %s tokens',
                (int)($stats['read'] ?? 0),
                isset($stats['available']) ? (string)$stats['available'] : '?',
                implode(', ', array_slice((array)($stats['ids'] ?? []), 0, 6)),
                number_format($tokens));
            if ((int)($stats['withheld'] ?? 0) > 0) {
                $label .= sprintf(', %d withheld for space', (int)$stats['withheld']);
            }
            if ((int)($stats['unknown_ids'] ?? 0) > 0) {
                $label .= sprintf(', %d unknown id%s', (int)$stats['unknown_ids'], (int)$stats['unknown_ids'] === 1 ? '' : 's');
            }

            return $label;
        }

        if ($kind === 'search') {
            return sprintf('App: phrase search over %s units — %d passage%s matched, ≈ %s tokens%s',
                isset($stats['available']) ? (string)$stats['available'] : '?',
                (int)($stats['matched'] ?? 0),
                (int)($stats['matched'] ?? 0) === 1 ? '' : 's',
                number_format($tokens),
                !empty($stats['map']) ? ', the contents index rode along' : '');
        }

        return sprintf('App: returned ≈ %s tokens from %s', number_format($tokens), self::toolTraceName($toolName));
    }

    /**
     * Does this conversation hold material a ledger could index? The indexes decide, not the payloads — a row whose
     * chunks were evicted still has its `chunk_map`, which is exactly what makes it readable later.
     */
    private function sessionHasStoredMaterial(int $sessionId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM chat_history WHERE session_id = ? AND message_type = 'data_fetching'
               AND (chunk_map IS NOT NULL OR backing_chunks IS NOT NULL OR selected_chunks IS NOT NULL) LIMIT 1",
            [$sessionId]
        );

        return $rows !== [];
    }

    private function reasoningModeEffort(?string $stored): array
    {
        $stored = $stored ?: 'medium';
        if ($stored === 'off') {
            return ['instruct', null];
        }
        if (in_array($stored, ['low', 'medium', 'high'], true)) {
            return ['thinking', $stored];
        }
        return ['thinking', 'medium'];
    }

    /**
     * Emit `thought_complete` at most once per pass. The streaming callbacks reach it from
     * several branches (native reasoning ending, a close tag, the end of the buffer), and a
     * second emission re-runs the client's finish handler — which re-renders the container the
     * typewriter writes into and detaches it, so later reasoning lands nowhere and the thought
     * only appears when the turn ends.
     */
    private function emitThoughtCompleteOnce(callable $emit, bool &$sent): void
    {
        if ($sent) {
            return;
        }
        $emit('thought_complete', []);
        $sent = true;
    }

    /**
     * Integrated first pass: one tool-capable streaming inference. Streams
     * reasoning live (via the reasoning SSE) so the thought window runs on
     * BOTH normal and tool turns — the tool-planning thought is shown and the
     * answer-pass reasoning appends to it. Returns the structured result from
     * AgentManager::chatToolCapable.
     *
     * @return array{finish_reason:string, content:string, tool_calls:?array, usage:?array}
     */

    private function firstPass(array $messages, callable $emit, bool $isEditorMode = false, ?string $mode = null, ?string $effort = null, bool $noSearch = false): array
    {
        $utf8Buffer = '';
        $contentEmitted = false;
        $contentChars = 0;
        $thoughtCompleteSent = false;
        $firstReasoningTs = null;

        $result = $this->agent->chatToolCapable(
            $messages,
            $this->buildToolSchemas($isEditorMode, $noSearch),
            'auto',
            function ($chunk, $type) use ($emit, &$utf8Buffer, &$contentEmitted, &$contentChars, &$thoughtCompleteSent, &$firstReasoningTs) {
                if ($type === 'reasoning') {
                    if ($firstReasoningTs === null) {
                        $firstReasoningTs = microtime(true);
                    }
                    $emit('reasoning', ['chunk' => $chunk, 't0' => $firstReasoningTs]);
                    return;
                }

                $utf8Buffer .= $chunk;
                if (!mb_check_encoding($utf8Buffer, 'UTF-8')) {
                    return;
                }

                $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
                $contentEmitted = true;
                $contentChars += mb_strlen($utf8Buffer);
                $emit('token', ['chunk' => $utf8Buffer]);
                $utf8Buffer = '';
            },
            null,
            null,
            $this->resolveAnswerMaxTokens($messages),
            $mode,
            $effort
        );

        if ($utf8Buffer !== '') {
            $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
            $contentEmitted = true;
            $emit('token', ['chunk' => mb_convert_encoding($utf8Buffer, 'UTF-8', 'UTF-8')]);
            $utf8Buffer = '';
        }

        // $contentEmitted alone is true even for an empty delta, which logged faults for turns that emitted
        // nothing at all (rows #2418/#2455, chars=0). Require actual content.
        if ($contentChars > 0 && $contentEmitted && !empty($result['tool_calls'])) {
            \App\Logger::logEvent('content_before_tool', 'Content emitted before tool_calls in the integrated first pass', [
                'content_before_tool_chars' => $contentChars,
                'tool' => $result['tool_calls'][0]['function']['name'] ?? null,
            ], 'warn', 'ChatManager::firstPass');
        }

        return $result;
    }

    public function streamAgentResponse(array $messages, callable $emit, ?string $mode = null, ?string $effort = null, ?array $tools = null): string
    {
        $aiResponse = '';
        $utf8_buffer = '';
        $inJsonTool = false;
        $jsonBraceDepth = 0;
        $inThought = false;
        $thoughtBuffer = '';
        $preThoughtBuffer = '';
        $isStartOfResponse = true;
        $nativeReasoningSeen = false;
        $firstReasoningTs = null;

        // Context-aware output budget (Part B): the answer pass must leave
        // content headroom beyond the reasoning budget so a full-budget
        // thinking trace can't burn the whole completion into empty content.
        // Clamped by actual context headroom (prompt size vs LLM_CTX_SIZE).
        $answerMaxTokens = $this->resolveAnswerMaxTokens($messages);
        // One `thought_complete` per streamed pass. This method is its own scope, so it needs
        // its own guard: the first pass has one, and sharing the other's would leave this one
        // unset (the guard is typed, so that is a hard failure, not a silent skip).
        $thoughtCompleteSent = false;

        $this->agent->chat($messages, true, function($chunk, $type = 'content') use ($emit, &$aiResponse, &$utf8_buffer, &$inJsonTool, &$jsonBraceDepth, &$inThought, &$thoughtBuffer, &$preThoughtBuffer, &$isStartOfResponse, &$nativeReasoningSeen, &$firstReasoningTs, &$thoughtCompleteSent) {
            if ($type === 'reasoning') {
                $nativeReasoningSeen = true;
                if ($firstReasoningTs === null) {
                    $firstReasoningTs = microtime(true);
                }
                $emit('reasoning', ['chunk' => $chunk, 't0' => $firstReasoningTs]);
                return;
            }

            if ($nativeReasoningSeen) {
                $nativeReasoningSeen = false;
                $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
            }

            $aiResponse .= $chunk;

            // In-thought: accumulate into buffer, check for close tag in full buffer.
            // Previous per-chunk closesThought() missed close tags split across chunks.
            if ($inThought) {
                $thoughtBuffer .= $chunk;

                if (\App\ThoughtExtractor::containsCloseTag($thoughtBuffer)) {
                    $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
                    $inThought = false;

                    if (!empty($extracted['thought'])) {
                        $emit('reasoning', ['chunk' => $extracted['thought']]);
                    }
                    $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
                    $thoughtBuffer = '';

                    if (!empty($extracted['content'])) {
                        $chunk = $extracted['content'];
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            }

            // Pre-thought: accumulate chunks in a buffer so we can detect open tags
            // that span chunk boundaries (e.g. "<|" + "channel" + "|>" + "thought").
            // Do NOT check opensThought() per-chunk — it misses split tags.
            $preThoughtBuffer .= $chunk;

            $openPos = \App\ThoughtExtractor::openTagPosition($preThoughtBuffer);
            if ($openPos !== -1) {
                if ($openPos > 0) {
                    $beforeText = substr($preThoughtBuffer, 0, $openPos);
                    \App\Logger::info('Thought open tag: emitting text before tag', ['len' => strlen($beforeText)]);
                    $emit('token', ['chunk' => $beforeText]);
                }

                $inThought = true;
                $thoughtBuffer = substr($preThoughtBuffer, $openPos);
                $preThoughtBuffer = '';

                if (\App\ThoughtExtractor::containsCloseTag($thoughtBuffer)) {
                    $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
                    $inThought = false;

                    if (!empty($extracted['thought'])) {
                        $emit('reasoning', ['chunk' => $extracted['thought']]);
                    }
                    $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
                    $thoughtBuffer = '';

                    if (!empty($extracted['content'])) {
                        $chunk = $extracted['content'];
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            }

            if (strlen($preThoughtBuffer) > \App\ThoughtExtractor::MAX_OPEN_TAG_LEN) {
                $chunk = $preThoughtBuffer;
                $preThoughtBuffer = '';
            } else {
                return;
            }

            // Suppress native tokenizer tool-call blocks (Gemma 4 / Qwen)
            if (str_contains($chunk, '<|tool_call|>') || str_contains($chunk, '<|tool_call>')) {
                $inJsonTool = true;
                return;
            }
            if ($inJsonTool && (str_contains($chunk, '<|tool_call|>') || str_contains($chunk, '</tool_call>') || str_contains($chunk, '<tool_call|>'))) {
                $inJsonTool = false;
                return;
            }

            if ($inJsonTool) {
                $jsonBraceDepth += substr_count($chunk, '{') - substr_count($chunk, '}');
                if ($jsonBraceDepth <= 0) {
                    $inJsonTool = false;
                    $jsonBraceDepth = 0;
                }
                return;
            }

            $utf8_buffer .= $chunk;

            if (mb_check_encoding($utf8_buffer, 'UTF-8')) {
                // Strip old [System Routing Hint: ...] from output
                $clean = preg_replace('/\[System Routing Hint:[^\]]*\]/s', '', $utf8_buffer);

                if ($clean === '') {
                    $utf8_buffer = '';
                    return;
                }

                // Detect JSON tool calls (legacy format)
                if (str_contains($clean, '{')) {
                    if (preg_match('/"tool"\s*:/', $clean)) {
                        $inJsonTool = true;
                        $jsonStart = strrpos($clean, '{');
                        if ($jsonStart !== false) {
                            $beforeJson = trim(substr($clean, 0, $jsonStart));
                            if (!empty($beforeJson)) {
                                $emit('token', ['chunk' => $beforeJson]);
                            }
                            $jsonPart = substr($clean, $jsonStart);
                            $jsonBraceDepth = substr_count($jsonPart, '{') - substr_count($jsonPart, '}');
                            if ($jsonBraceDepth <= 0) {
                                $lastBrace = strrpos($jsonPart, '}');
                                $afterJson = trim(substr($jsonPart, $lastBrace + 1));
                                if (!empty($afterJson)) {
                                    $emit('token', ['chunk' => $afterJson]);
                                }
                            }
                        } else {
                            $jsonBraceDepth = 1;
                        }
                        $utf8_buffer = '';
                        return;
                    }

                    if (mb_strlen($clean) > 150) {
                        $emit('token', ['chunk' => $clean]);
                        $utf8_buffer = '';
                    }
                    return;
                }

                if ($isStartOfResponse && !empty(trim($clean))) {
                    $trimmedClean = ltrim($clean);
                    if (str_starts_with($trimmedClean, '`')) {
                        $clean = preg_replace('/^`+/s', '', $trimmedClean);
                        $clean = preg_replace('/^(markdown|text|html|txt)?\s+/si', '', $clean);
                    }
                    if (trim($clean) !== '' && !str_starts_with(trim($clean), '`')) {
                        $isStartOfResponse = false;
                    }
                }

                $emit('token', ['chunk' => $clean]);
                $utf8_buffer = '';
            }
        }, null, 'answer', $mode, $effort, $answerMaxTokens, $tools, 'none');

        // Drain any leftover content from the pre-thought buffer.
        // If the stream ends while the buffer is still < MAX_OPEN_TAG_LEN
        // and no open tag was found, the accumulated text was never emitted.
        if (!empty($preThoughtBuffer)) {
            $emit('token', ['chunk' => $preThoughtBuffer]);
        }

        // If we're still in a thought block at stream end (no close tag found),
        // emit whatever was accumulated as reasoning and close the block.
        if ($inThought && !empty($thoughtBuffer)) {
            $extracted = \App\ThoughtExtractor::extract($thoughtBuffer);
            if (!empty($extracted['thought'])) {
                $emit('reasoning', ['chunk' => $extracted['thought']]);
            }
            $this->emitThoughtCompleteOnce($emit, $thoughtCompleteSent);
            if (!empty($extracted['content'])) {
                $emit('token', ['chunk' => $extracted['content']]);
            }
        }

        if (!empty($utf8_buffer) && !$inJsonTool) {
            $emit('token', ['chunk' => mb_convert_encoding($utf8_buffer, 'UTF-8', 'UTF-8')]);
        }

        // Part C: narrow empty-answer retry. Fires only on the exact signature —
        // reasoning produced, content empty, and the output budget was exhausted
        // (completion_tokens >= max_tokens) — then retries once in instruct mode
        // (no thinking) so the user gets content instead of a silent empty bubble.
        if ($aiResponse === ''
            && $this->agent->lastReasoningChars > 0
            && ($this->agent->lastUsage['completion_tokens'] ?? 0) >= $answerMaxTokens) {
            \App\Logger::logEvent('llm_empty_answer_thinking_exhausted', 'Answer pass burned its whole output budget on reasoning with zero content; retrying once in instruct mode', [
                'completion_tokens' => $this->agent->lastUsage['completion_tokens'] ?? 0,
                'reasoning_chars' => $this->agent->lastReasoningChars,
                'max_tokens' => $answerMaxTokens,
            ], 'error', 'ChatManager::streamAgentResponse');
            return $this->retryAnswerInstruct($messages, $emit, $answerMaxTokens);
        }

        return $aiResponse;
    }

    /**
     * Hard output cap for the answer pass: a fixed 8192, clamped by actual
     * context headroom (LLM_CTX_SIZE minus estimated prompt tokens minus safety
     * margin). The cap is only a safety net against a stuck model looping past a
     * sane answer length — reasoning depth is governed by reasoning_effort, not
     * by sizing this cap to a fixed reasoning budget.
     */
    private function resolveAnswerMaxTokens(array $messages): int
    {
        $maxTokens = 8192;

        $ctxSize = (int) Config::get('LLM_CTX_SIZE', 0);
        if ($ctxSize > 0) {
            $promptTokens = 0;
            foreach ($messages as $m) {
                $c = $m['content'] ?? '';
                if (is_array($c)) {
                    $c = $c[0]['text'] ?? '';
                }
                $promptTokens += ($this->countTokens)((string) $c);
            }
            $headroom = $ctxSize - $promptTokens - self::SAFETY_MARGIN_TOKENS;
            if ($headroom > 0) {
                $maxTokens = min($maxTokens, $headroom);
            }
        }
        return $maxTokens;
    }

    /**
     * Bounded empty-answer retry: re-runs the answer pass in instruct mode
     * (reasoning off) so content is emitted directly instead of being consumed
     * by a reasoning trace. Streams through the same emit callback.
     */
    private function retryAnswerInstruct(array $messages, callable $emit, int $maxTokens): string
    {
        return $this->agent->chat(
            $messages,
            true,
            function ($chunk, $type = 'content') use ($emit) {
                if ($type === 'reasoning') {
                    $emit('reasoning', ['chunk' => $chunk]);
                    return;
                }
                $emit('token', ['chunk' => $chunk]);
            },
            null,
            'answer_retry',
            'instruct',
            null,
            $maxTokens
        );
    }

    /**
     * Deferred evidence atomization. Runs at the start of a turn, before this
     * turn's messages are built, when the session's accumulated un-atomized raw
     * evidence crosses the backlog threshold — or when context headroom is tight
     * (safety override).
     *
     * PARTIAL reclamation: the threshold means "cleanup is required", not "clean
     * everything". Rows are sorted largest-first and atomized ONE AT A TIME
     * (each is a single narrow condenseBatched call, so a small model is never
     * handed a mixed evidence landfill); after each row the remaining backlog
     * and headroom are recalculated and the loop stops as soon as the backlog is
     * back below target AND safety headroom is restored. A giant maintenance
     * stall (e.g. three ~4s condenser calls back-to-back) is avoided; later
     * evidence accumulation triggers the next row's condensation.
     */
    private function atomizeBacklogIfNeeded(int $sessionId, callable $emit, ?SourceCondenser $condenser = null, ?int $ctxSize = null): void
    {
        $rows = $this->db->query(
            "SELECT id, token_estimate, search_query, selected_chunks, backing_chunks
             FROM chat_history
             WHERE session_id = ? AND message_type = 'data_fetching'
               AND raw_evicted = 0 AND atomic_context IS NULL",
            [$sessionId]
        );
        if (empty($rows)) {
            return;
        }

        $backlog = 0;
        foreach ($rows as $r) {
            $backlog += (int)($r['token_estimate'] ?? 0);
        }
        if ($backlog <= 0) {
            return;
        }

        $ctxSize = $ctxSize ?? (int) Config::get('LLM_CTX_SIZE', 0);
        $session = $this->db->query('SELECT context_tokens FROM chat_sessions WHERE id = ?', [$sessionId]);
        $sessionTokens = (int)($session[0]['context_tokens'] ?? 0);
        $headroom = $ctxSize - $sessionTokens - self::OUTPUT_RESERVE_TOKENS - self::SAFETY_MARGIN_TOKENS;

        if (!AtomizationPolicy::shouldAtomizeBacklog($backlog, $headroom, $ctxSize)) {
            return;
        }

        // Largest-first ordering so the highest-value rows (biggest raw evidence)
        // are reclaimed first, minimizing the number of condenser calls.
        usort($rows, fn($a, $b) => (int)($b['token_estimate'] ?? 0) <=> (int)($a['token_estimate'] ?? 0));

        // Rebuild the pending list (largest-first): one entry per un-atomized
        // row, from its persisted selected chunks (fallback to backing_chunks for
        // rows that predate selected_chunks persistence).
        $pending = [];
        foreach ($rows as $r) {
            $chunks = $this->decodeChunks((string)($r['selected_chunks'] ?? ''));
            if (empty($chunks)) {
                $chunks = $this->decodeChunks((string)($r['backing_chunks'] ?? ''));
            }
            if (empty($chunks)) {
                continue;
            }
            $pending[] = [
                'id' => (int)$r['id'],
                'token_estimate' => (int)($r['token_estimate'] ?? 0),
                'chunks' => $chunks,
                'query' => (string)($r['search_query'] ?? ''),
            ];
        }

        if (empty($pending)) {
            return;
        }

        \App\Logger::logEvent(
            'atomization_backlog',
            "Compacting evidence backlog of {$backlog} tokens across " . count($pending) . ' rows (headroom ' . $headroom . ')',
            [
                'backlog_tokens' => $backlog,
                'rows' => count($pending),
                'headroom' => $headroom,
                'consolidation_ms_ema' => round($this->atomizationStats->consolidationMsEma(), 1),
            ],
            'info',
            'ChatManager::atomizeBacklogIfNeeded'
        );

        $emit('status', ['text' => 'Compacting evidence...']);
        $emit('consolidation_start', ['rows' => count($pending)]);

        $condenser = $condenser ?? new SourceCondenser($this->agent);
        $remainingBacklog = $backlog;
        $remainingHeadroom = $headroom;
        $persisted = 0;
        $failed = 0;

        foreach ($pending as $item) {
            // Re-evaluate after every row: stop as soon as the backlog is below
            // target AND safety headroom is restored. Continue only while more
            // reclaim is actually required.
            if (!AtomizationPolicy::shouldAtomizeBacklog($remainingBacklog, $remainingHeadroom, $ctxSize)) {
                break;
            }

            // condenseRow returns the atom token count on success (0 = empty,
            // -1 = threw). Reclaimed = raw − atoms, NOT the full raw row.
            $atomTokens = $this->condenseRow($item['id'], $item['chunks'], $item['token_estimate'], $item['query'], $condenser, $emit);
            if ($atomTokens > 0) {
                $persisted++;
                $reclaimed = max(0, $item['token_estimate'] - $atomTokens);
                $remainingBacklog = max(0, $remainingBacklog - $reclaimed);
                $remainingHeadroom += $reclaimed; // atoms still inject; only the shrink is reclaimed
            } elseif ($atomTokens === -1) {
                $failed++;
            }
            // atomTokens 0 (empty extraction): row stays raw, not a failure.
        }

        if ($failed > 0) {
            $emit('consolidation_error', ['failed' => $failed, 'persisted' => $persisted]);
        } else {
            $emit('consolidation_done', ['persisted' => $persisted]);
        }
        $this->recordConsolidationTiming();
    }

    /**
     * Shown to the user — as if the model had emitted it — when a turn ends because the stop control was pressed. The
     * owner's exact wording; it is a constant so the client can append the identical string locally and the live view
     * matches what the server persists (plan R15).
     */
    public const STOP_NOTICE = 'Generation has been stopped by the user.';

    /** Append the stop notice on its own line, or use it alone when the turn had written nothing yet. */
    private static function withStopNotice(string $text): string
    {
        $text = rtrim($text);

        return $text === '' ? self::STOP_NOTICE : $text . "\n\n" . self::STOP_NOTICE;
    }

    /**
     * Was stop pressed for this session? Reading the flag consumes it, because one stop ends exactly one turn — a flag
     * left behind would stop the user's *next* turn too. Redis trouble is never read as "the user stopped".
     */
    private function stopRequested(int $sessionId): bool
    {
        try {
            if (Cache::get('turn_stop:' . $sessionId) === null) {
                return false;
            }
            Cache::delete('turn_stop:' . $sessionId);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Condense ONE evidence row and emit what the UI needs to show the reclaim. The shared step of both callers: the deferred
     * backlog pass (policy-gated, at turn start) and the enrichment job (every row a finished turn fetched). Keeping
     * one implementation is deliberate — the two must never drift apart, since the same rows can be reached by
     * either path.
     *
     * @return int the atom token count when atoms were persisted (> 0); 0 when extraction was empty (row stays raw);
     *             -1 when condensation threw
     */
    private function condenseRow(int $rowId, array $chunks, int $rawTokens, string $query, SourceCondenser $condenser, callable $emit): int
    {
        $atomTokens = $this->atomizeRow($rowId, $chunks, $query, $condenser);
        if ($atomTokens > 0) {
            $emit('context_data_atomized', [
                'id' => $rowId,
                'raw_tokens' => $rawTokens,
                'atom_tokens' => $atomTokens,
                'reclaimed' => max(0, $rawTokens - $atomTokens),
            ]);
        }

        return $atomTokens;
    }

    /**
     * Enrich specific fetch rows: facts plus the contents index, one row at a time, in the order given.
     *
     * This is what the background job runs (and what the probe drives). It deliberately does NOT consult the backlog
     * policy — a turn's own source gets its notes whether or not the session has accumulated 30k tokens of un-atomized
     * raw (plan R3). It also does NOT take the inference lock: the caller owns it (the worker holds it for its whole
     * run), because the lock's status message is how the UI knows what is happening.
     *
     * Idempotent by design: a row that already carries atoms, a row that is not in this session, and a row with no
     * chunks are all counted as skipped and left alone. So a second run over the same ids — or a crash and retry —
     * costs nothing.
     *
     * @param int[]               $rowIds
     * @param SourceCondenser|null $condenser injectable for the deterministic test; a real one otherwise
     * @return array{rows:int,enriched:int,empty:int,failed:int,skipped:int,atoms:int,reclaimed:int,ms:int}
     */
    public function enrichRows(int $sessionId, array $rowIds, ?callable $emit = null, ?SourceCondenser $condenser = null): array
    {
        $emit = $emit ?? static function (string $event, array $data = []): void {
        };

        // Ids are normalised and de-duplicated, but an unusable one (zero, negative, junk) is counted as skipped
        // rather than dropped: a caller handing over a bad id should see it in the summary, not lose it silently.
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        $summary = [
            'rows' => count($rowIds),
            'enriched' => 0,
            'empty' => 0,
            'failed' => 0,
            'skipped' => count(array_filter($rowIds, static fn(int $id): bool => $id <= 0)),
            'atoms' => 0,
            'reclaimed' => 0,
            'ms' => 0,
        ];
        $rowIds = array_values(array_filter($rowIds, static fn(int $id): bool => $id > 0));
        if ($rowIds === []) {
            return $summary;
        }

        $started = microtime(true);
        $condenser = $condenser ?? new SourceCondenser($this->agent);

        foreach ($rowIds as $rowId) {
            $row = $this->db->query(
                'SELECT id, token_estimate, search_query, selected_chunks, backing_chunks, raw_evicted, atomic_context'
                . ' FROM chat_history WHERE id = ? AND session_id = ?',
                [$rowId, $sessionId]
            )[0] ?? null;

            if ($row === null) {
                // Gone, or another session's row: never touch it.
                $summary['skipped']++;
                continue;
            }
            if ((int)($row['raw_evicted'] ?? 0) === 1 || $row['atomic_context'] !== null) {
                $summary['skipped']++;
                continue;
            }
            $chunks = $this->decodeChunks((string)($row['selected_chunks'] ?? ''));
            if (empty($chunks)) {
                $chunks = $this->decodeChunks((string)($row['backing_chunks'] ?? ''));
            }
            if (empty($chunks)) {
                $summary['skipped']++;
                continue;
            }

            $rawTokens = (int)($row['token_estimate'] ?? 0);
            $atomTokens = $this->condenseRow($rowId, $chunks, $rawTokens, (string)($row['search_query'] ?? ''), $condenser, $emit);
            if ($atomTokens > 0) {
                $summary['enriched']++;
                $summary['atoms'] += $atomTokens;
                $summary['reclaimed'] += max(0, $rawTokens - $atomTokens);
            } elseif ($atomTokens === 0) {
                $summary['empty']++;
            } else {
                $summary['failed']++;
            }
        }

        $summary['ms'] = (int)round((microtime(true) - $started) * 1000);

        return $summary;
    }

    /** Decode a persisted WebChunk[] JSON column back into WebChunk objects. */
    public static function decodeChunks(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $chunks = [];
        foreach ($raw as $c) {
            if (is_array($c)) {
                $chunks[] = WebChunk::fromArray($c);
            }
        }
        return $chunks;
    }

    /** Feed the just-run consolidation's server-measured latency into the EMA. */
    private function recordConsolidationTiming(): void
    {
        $t = $this->agent->lastTimings ?? [];
        $total = (float)($t['prompt_ms'] ?? 0.0) + (float)($t['predicted_ms'] ?? 0.0);
        if ($total > 0.0) {
            $this->atomizationStats->recordConsolidation($total);
        }
    }

    /**
     * Condense ONE evidence row (one tool result) into atomic claims and persist
     * them on that row's atomic_context. No SSE emission here — the caller owns
     * the consolidation_start/done/error envelope.
     *
     * @return int the atom token count when atoms were persisted (> 0); 0 when
     *             extraction was empty (raw stays active, not a hard failure);
     *             -1 when condensation threw.
     */
    private function atomizeRow(int $rowId, array $chunks, string $query, SourceCondenser $condenser): int
    {
        $filtered = array_values(array_filter($chunks, fn($c) => strlen($c->text) < 50000));
        if (empty($filtered)) {
            $filtered = $chunks;
        }

        try {
            // One inference produces both artifacts: the facts that will answer, and the map of what
            // each passage contains (built from the STORED raw unit text, never from a condensed form,
            // or the map would inherit the shape of the question that fetched the source).
            $enriched = $condenser->enrichBatched($filtered, $query);
        } catch (\Throwable $e) {
            \App\Logger::logEvent('consolidation_failed', 'Evidence atomization failed: ' . $e->getMessage(), [
                'row_id' => $rowId,
                'error' => $e->getMessage(),
            ], 'error', 'ChatManager::atomizeRow');
            return -1;
        }

        $claims = $enriched['claims'];
        if (empty($claims)) {
            \App\Logger::logEvent('consolidation_empty', 'Evidence atomization produced no atoms; raw evidence stays active', [
                'row_id' => $rowId,
            ], 'warn', 'ChatManager::atomizeRow');
            return 0;
        }

        $atomTokens = $this->atomTokenCount($claims);

        $this->db->update('chat_history', [
            'atomic_context' => json_encode($claims, JSON_UNESCAPED_UNICODE),
            'atomic_tokens' => $atomTokens,
            'chunk_map' => ChunkMap::encode($enriched['map']),
            'raw_evicted' => 1,
        ], ['id' => $rowId]);

        \App\Logger::logEvent('consolidation_ok', 'Evidence atomization persisted atoms', [
            'row_id' => $rowId,
            'claims' => count($claims),
            'atom_tokens' => $atomTokens,
            'map_entries' => count($enriched['map']),
            'map_formatted' => $enriched['formatted'],
        ], 'info', 'ChatManager::atomizeRow');
        return $atomTokens;
    }

    /**
     * The instruction that closes a tool turn: answer from what was gathered, and if that is not enough,
     * say what is missing instead of asking again. Stored on the turn's last evidence row rather than
     * appended to the turn's own array, so every later prompt re-emits it in the same position — a
     * reminder living only in this turn's array would make the next prompt diverge exactly there and cost
     * the engine the whole cached prefix behind it.
     */
    private static function runtimeReminder(string $query): string
    {
        return "RUNTIME REMINDER:\nAnswer the user's original request using the evidence above. If the evidence is not enough to answer fully, say what you found and what additional search would help, then ask the user whether they want you to run it — do not emit another tool call. Request: \"{$query}\"";
    }

    /**
     * Real token count of the atom set as it will be injected — the `[S#] claim` lines
     * rendered by PromptAssemblyService::renderAtomLines. Uses the injectable
     * TokenCounter (llama /tokenize, md5-cached, chars/4 fallback) so the viewer's
     * arrow shows real reclaimed tokens, not a chars/4 guess.
     */
    private function atomTokenCount(array $claims): int
    {
        $lines = PromptAssemblyService::renderAtomLines($claims);
        return ($this->countTokens)($lines);
    }


}
