<?php

namespace App\Services\Tools;

use App\Config;
use App\Database;
use App\Logger;
use App\Search\Bm25Retriever;
use App\Search\ChunkMap;
use App\Search\WebChunk;

/**
 * Access to the sources already fetched in this conversation.
 *
 * One job: reach the raw text of a source the user already has. A call with no arguments answers with
 * the LEDGER — the stored sources and what each passage covers — and that answer is what tells the model
 * how to read a passage: nothing about the table of contents is advertised up front, because it only
 * matters once the model has decided the answer may be in there.
 *
 * Arguments select the rest: `ids` reads exactly those passages, `queries` finds passages by phrase
 * (the second path, for when no passage is named and the phrase itself is the way in).
 *
 * Nothing here performs network access, calls a model, or allocates source ids: retrieved material is
 * TRANSIENT for the current turn, is not promoted to atomic_context, and keeps its original S#-C#
 * provenance, so user-visible citations resolve to the original [S#].
 */
class SearchSessionEvidenceTool
{
    private const MAX_RESULTS = 10;

    /** The estimator the rest of the app plans with (TokenCounter's chars/4 fallback). */
    private const CHARS_PER_TOKEN = 4;

    /** A tiny window still gets a useful slice: an empty read would be worse than a small one. */
    private const MIN_READ_CHARS = 4000;

    private Database $db;
    private Bm25Retriever $retriever;

    /** @var string[] source IDs of chunks actually returned by the last execute() */
    private array $lastRetrievedSourceIds = [];

    /**
     * The numbers of the last call, for the execution trace: how many units were asked for, how many were read,
     * what was withheld for space, and the size. "Returned 2.9k chars" cannot tell a full load from a fragment;
     * read-vs-available and read-vs-withheld can, so the trace carries them.
     *
     * @var array<string,mixed>
     */
    private array $lastCallStats = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->retriever = new Bm25Retriever();
    }

    public function execute(array $params, int $sessionId, array $messages, callable $emit, string $originalJson): string
    {
        $this->lastRetrievedSourceIds = [];
        $this->lastCallStats = [];

        $ids = $this->idList($params['ids'] ?? ($params['chunk_ids'] ?? null));
        if ($ids !== []) {
            return $this->readUnits($params, $sessionId);
        }

        if (trim((string)($params['query'] ?? '')) !== '' || !empty($params['queries'])) {
            return $this->searchEvidence($params, $sessionId);
        }

        // No arguments: answer with the ledger. This is the call the tool's description invites, and the
        // ledger's own text says how to read a passage out of it.
        return $this->browseMap($sessionId);
    }

    /** @return string[] */
    public function getLastRetrievedSourceIds(): array
    {
        return $this->lastRetrievedSourceIds;
    }

    public function resetRetrievedSourceIds(): void
    {
        $this->lastRetrievedSourceIds = [];
    }

    /** @return array<string,mixed> the numbers of the last call (see $lastCallStats) */
    public function getLastCallStats(): array
    {
        return $this->lastCallStats;
    }

    public function resetLastCallStats(): void
    {
        $this->lastCallStats = [];
    }

    // ----------------------------------------------------------------- search

    private function searchEvidence(array $params, int $sessionId): string
    {
        $query = trim((string)($params['query'] ?? ''));
        if ($query === '') {
            return $this->noEvidence('(empty query)');
        }

        $sourceFilter = $this->sourceFilter($params['source_ids'] ?? null);

        $chunks = [];
        foreach ($this->activeRows($sessionId) as $row) {
            foreach ($this->rowChunks($row) as $chunk) {
                if ($sourceFilter !== null && !in_array($chunk->sourceId, $sourceFilter, true)) {
                    continue;
                }
                $chunks[] = $chunk;
            }
        }

        if (empty($chunks)) {
            return $this->noEvidence($query);
        }

        $ranked = $this->retriever->rankRawWithScores($chunks, $query, $query);
        if (empty($ranked)) {
            return $this->noMatchWithMap($query, $sessionId, count($chunks));
        }

        $ranked = array_slice($ranked, 0, self::MAX_RESULTS);

        $lines = [];
        foreach ($ranked as $entry) {
            /** @var WebChunk $chunk */
            $chunk = $entry['chunk'];
            $this->lastRetrievedSourceIds[] = $chunk->sourceId;
            $lines[] = "[{$chunk->chunkId}] (score " . number_format($entry['score'], 2) . ")\n" . trim($chunk->text);
        }
        $this->lastRetrievedSourceIds = array_values(array_unique($this->lastRetrievedSourceIds));

        $filterNote = $sourceFilter !== null ? ' (source filter: ' . implode(', ', $sourceFilter) . ')' : '';
        $body = "SESSION EVIDENCE — passages from sources already fetched in this conversation{$filterNote}:\n\n"
            . implode("\n\n", $lines);

        // Whether these passages answer the question cannot be known before reading them, and the words that
        // found them may not be the words the needed passage uses. So the same answer carries the map: what
        // else is stored, described, to be picked by MEANING rather than by keyword overlap. This is the only
        // moment the structure is any use — a chapter cannot be chosen before it has been seen.
        $appendix = $this->mapAppendix($sessionId,
            'Did those passages answer the question? If not, this is everything else stored in this conversation. '
            . 'Choose by what each passage covers, not by whether it repeats the question\'s words — a passage with '
            . 'none of the user\'s words may be the one that answers it:');
        $this->lastCallStats = [
            'kind' => 'search',
            'matched' => count($ranked),
            'available' => count($chunks),
            'chars' => mb_strlen($body . $appendix),
            'map' => $appendix !== '',
        ];

        return $body . $appendix;
    }

    /**
     * No stored passage matched the words. That is exactly the case the map exists for, so it rides here too:
     * the model must not conclude the material is absent, because a passage that never uses those words is
     * still reachable by what it covers.
     */
    private function noMatchWithMap(string $query, int $sessionId, int $available = 0): string
    {
        $appendix = $this->mapAppendix($sessionId,
            'Everything stored in this conversation, described. Choose by coverage, not by whether it '
            . 'repeats those words:');
        $this->lastCallStats = [
            'kind' => 'search',
            'matched' => 0,
            'available' => $available,
            'chars' => mb_strlen($appendix),
            'map' => $appendix !== '',
        ];

        return 'SESSION EVIDENCE — no stored passage matches "' . $query . '". '
            . 'That does not mean the material is absent: pick a passage by what it covers.'
            . $appendix;
    }

    /** The map, with a lead-in that says what to do with it. Empty string when nothing is stored. */
    private function mapAppendix(int $sessionId, string $lead): string
    {
        $map = $this->mapSection($sessionId);
        if ($map === '') {
            return '';
        }

        return "\n\n---\n" . $lead . "\n\n" . $map
            . "\n\nTo read any of them in full, call this tool again with ids, e.g. ids: [\""
            . $this->firstUnitId([$map]) . '"].';
    }

    // -------------------------------------------------------------------- map

    /**
     * The stored passages as lines the model can choose from — the map. It rides with a search's results (so
     * the structure arrives at the moment it matters) and comes back on its own when the tool is called with
     * no words at all.
     */
    private function mapSection(int $sessionId): string
    {
        $blocks = $this->mapBlocks($sessionId);

        return $blocks === [] ? '' : implode("\n\n", $blocks);
    }

    /**
     * One block per source: a header line, then one line per stored passage. No ranking and no model call —
     * this is a listing of storage, not a search. Sources without descriptions yet are still listed, with
     * their ids and heading paths, because addresses are storage metadata and cost nothing to state.
     *
     * @return string[]
     */
    private function mapBlocks(int $sessionId): array
    {
        $blocks = [];

        foreach ($this->activeRows($sessionId) as $row) {
            $chunks = $this->rowChunks($row);
            if (empty($chunks)) {
                continue;
            }

            $map = ChunkMap::decode($row['chunk_map'] ?? null);
            $sourceMeta = $this->rowSources($row);
            $tool = (string)($row['tool_name'] ?? 'unknown');

            foreach ($this->groupBySource($chunks) as $sourceId => $sourceChunks) {
                $meta = $sourceMeta[$sourceId] ?? [];
                $header = $sourceId . ' — ' . $this->sourceLabel($meta) . ' — acquired by ' . $tool;

                $entries = array_values(array_filter(
                    $map['entries'],
                    static fn(array $e): bool => str_starts_with((string)$e['chunkId'], $sourceId . '-C')
                ));

                if (empty($entries)) {
                    // No descriptions yet. The addresses are still free — ids and sizes are storage
                    // metadata, not a model call — so list them: the model can read any of these units
                    // by id, it simply gets no prose about what is inside one.
                    $addresses = [];
                    foreach ($sourceChunks as $chunk) {
                        $line = '[' . $chunk->chunkId . '] ' . ChunkMap::sizeLabel(strlen($chunk->text));
                        if (!empty($chunk->headingPath)) {
                            $line .= ' — ' . implode(' > ', $chunk->headingPath);
                        }
                        $addresses[] = $line . ' (not described yet)';
                    }
                    $blocks[] = $header . ' — ' . count($sourceChunks)
                        . " units, no map yet (a phrase search reaches its text):\n" . implode("\n", $addresses);
                    continue;
                }

                $status = $map['status'] === 'partial' ? ' — map still being built' : '';
                $blocks[] = $header . ' — ' . count($entries) . ' units' . $status . ":\n"
                    . ChunkMap::renderEntries($entries);
            }
        }

        return $blocks;
    }

    /** The map on its own, for a call that carries no words at all. */
    private function browseMap(int $sessionId): string
    {
        $map = $this->mapSection($sessionId);

        return $map === ''
            ? 'SESSION EVIDENCE MAP — no evidence has been stored in this conversation yet.'
            : "SESSION EVIDENCE MAP — the stored sources in this conversation and what each passage contains.\n"
                . 'To read any of them, call this tool again with ids, e.g. ids: ["'
                . $this->firstUnitId([$map]) . '"].' . "\n\n" . $map;
    }

    // ------------------------------------------------------------------- read

    /**
     * Raw text of named units: no ranking and no condensation, the ids the model asked for exactly as
     * stored, up to the read budget. Anything withheld is named so it can be asked for again.
     */
    private function readUnits(array $params, int $sessionId): string
    {
        $ids = $this->idList($params['ids'] ?? ($params['chunk_ids'] ?? null));

        $byId = [];
        foreach ($this->activeRows($sessionId) as $row) {
            foreach ($this->rowChunks($row) as $chunk) {
                $byId[$chunk->chunkId] ??= $chunk;
            }
        }

        if (empty($byId)) {
            return 'SESSION EVIDENCE — no evidence has been stored in this conversation yet.';
        }

        // A bare source id ("S1") is a request to read that source, and it is the form models reach for first — it
        // is the id the interface shows them in the evidence block. Expand it to that source's passages instead of
        // treating it as a phrase that matches nothing: measured, a reference-tier model asked to read "S1" on
        // every one of three runs and was told "no stored passage matches S1" each time.
        $expanded = [];
        foreach ($ids as $id) {
            if (preg_match('/^S\d+$/', $id) === 1) {
                $units = array_values(array_filter(
                    array_keys($byId),
                    static fn(string $unitId): bool => str_starts_with($unitId, $id . '-C')
                ));
                if ($units === []) {
                    $expanded[] = $id;   // unknown source: keep it so it is named back rather than silently dropped
                    continue;
                }
                foreach ($units as $unitId) {
                    $expanded[] = $unitId;
                }
                continue;
            }
            $expanded[] = $id;
        }
        $ids = array_values(array_unique($expanded));

        $maxChars = self::readBudgetChars((int) Config::get('LLM_CTX_SIZE', 0));
        $fit = ChunkMap::fitToBudget($ids, $byId, $maxChars);

        $sections = [];
        foreach ($fit['kept'] as $id) {
            $chunk = $byId[$id];
            $this->lastRetrievedSourceIds[] = $chunk->sourceId;
            $sections[] = "[{$chunk->chunkId}] " . $this->sourceLabelFromChunk($chunk) . "\n" . trim($chunk->text);
        }
        $this->lastRetrievedSourceIds = array_values(array_unique($this->lastRetrievedSourceIds));

        Logger::logEvent('evidence_units_read', 'Session evidence units read by id', [
            'requested' => count($ids),
            'read' => count($fit['kept']),
            'withheld' => count($fit['omitted']),
            'unknown_ids' => count($fit['missing']),
            'chars' => $fit['chars'],
            'budget_chars' => $maxChars,
        ], 'info', 'SearchSessionEvidenceTool::readUnits');

        // The same numbers ride the trace line: read N of M units, what was withheld, the size — that is what
        // says how much was actually loaded.
        $this->lastCallStats = [
            'kind' => 'read',
            'requested' => count($ids),
            'read' => count($fit['kept']),
            'withheld' => count($fit['omitted']),
            'unknown_ids' => count($fit['missing']),
            'chars' => $fit['chars'],
            'budget_chars' => $maxChars,
            'available' => count($byId),
            'ids' => array_slice($ids, 0, 10),
        ];

        if (empty($sections)) {
            return 'SESSION EVIDENCE — none of the requested unit ids exist in this conversation '
                . '(asked for: ' . implode(', ', array_slice($ids, 0, 20)) . '). '
                . 'Ask for the map to see which units are stored, or use a phrase search.';
        }

        $body = "SESSION EVIDENCE — the raw text of the requested units, exactly as stored:\n\n"
            . implode("\n\n", $sections);

        $notes = [];
        if (!empty($fit['omitted'])) {
            $notes[] = 'WITHHELD for space — these ids are stored and can be asked for in a second read: '
                . implode(', ', $fit['omitted'])
                . ' (' . ChunkMap::sizeLabel($this->charsOf($fit['omitted'], $byId)) . ' of text).';
        }
        if (!empty($fit['missing'])) {
            $notes[] = 'NOT STORED — these ids do not exist here: ' . implode(', ', $fit['missing']) . '.';
        }
        if (!empty($notes)) {
            $body .= "\n\n" . implode("\n", $notes);
        }

        return $body;
    }

    // -------------------------------------------------------------- row access

    /**
     * Rows whose evidence is live: raw still resident, or atoms present. A row that is fully off —
     * raw evicted and no atoms — is not browsable; that is the user's explicit removal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeRows(int $sessionId): array
    {
        return $this->db->query(
            "SELECT id, tool_name, search_query, source_map, backing_chunks, selected_chunks, chunk_map
             FROM chat_history
             WHERE session_id = ? AND message_type = 'data_fetching'
               AND (raw_evicted = 0 OR atomic_context IS NOT NULL)
             ORDER BY id ASC",
            [$sessionId]
        );
    }

    /**
     * The units one evidence row holds: backing_chunks is the full stored set, selected_chunks the
     * older column kept for rows written before it existed.
     *
     * @return WebChunk[]
     */
    private function rowChunks(array $row): array
    {
        $chunks = $this->decodeChunks((string)($row['backing_chunks'] ?? ''));
        if (empty($chunks)) {
            $chunks = $this->decodeChunks((string)($row['selected_chunks'] ?? ''));
        }

        return $chunks;
    }

    /** @return WebChunk[] */
    private function decodeChunks(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $chunks = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || empty($entry['sourceId']) || empty($entry['chunkId'])) {
                continue;
            }
            $chunks[] = WebChunk::fromArray($entry);
        }

        return $chunks;
    }

    /**
     * @param WebChunk[] $chunks
     * @return array<string, WebChunk[]>
     */
    private function groupBySource(array $chunks): array
    {
        $grouped = [];
        foreach ($chunks as $chunk) {
            $grouped[$chunk->sourceId][] = $chunk;
        }

        return $grouped;
    }

    /** @return array<string, array<string, mixed>> */
    private function rowSources(array $row): array
    {
        $decoded = json_decode((string)($row['source_map'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $meta */
    private function sourceLabel(array $meta): string
    {
        $title = trim((string)($meta['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $domain = trim((string)($meta['domain'] ?? ''));

        return $domain !== '' ? $domain : '(untitled)';
    }

    private function sourceLabelFromChunk(WebChunk $chunk): string
    {
        return trim($chunk->title) !== '' ? $chunk->title : $chunk->domain;
    }

    /**
     * @param string[] $ids
     * @param array<string, WebChunk> $byId
     */
    private function charsOf(array $ids, array $byId): int
    {
        $chars = 0;
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $chars += strlen($byId[$id]->text);
            }
        }

        return $chars;
    }

    /** @return string[]|null */
    private function sourceFilter(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $filter = array_values(array_filter(array_map('strval', $raw)));

        return empty($filter) ? null : $filter;
    }

    /** @return string[] */
    private function idList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id)) {
                continue;
            }
            // A model may hand over a bracketed citation ("[S1-C4]"); the id is what is inside.
            $id = strtoupper(trim($id, "[] \t\n\r\0\x0B"));
            // Either a passage id, or a bare source id ("S1") — which is the id the evidence block shows the model,
            // and the form it reaches for when it means "read that source". readUnits expands it to the source's
            // passages; dropping it here sent the call down the phrase path, where it matched nothing.
            if ($id !== '' && (preg_match('/^S\d+-C\d+$/', $id) === 1 || preg_match('/^S\d+$/', $id) === 1)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * What one read may return: half the context window, expressed in characters (the estimator the
     * app already plans with). The headroom term of that rule — never more than what is left after
     * the prompt so far and the answer reserve — needs the assembled prompt, so it lands with the
     * multi-round tool loop; until then this is the ceiling, and every read logs its real size.
     */
    public static function readBudgetChars(int $ctxSize): int
    {
        if ($ctxSize <= 0) {
            return self::MIN_READ_CHARS;
        }

        return max(self::MIN_READ_CHARS, (int) floor($ctxSize / 2) * self::CHARS_PER_TOKEN);
    }

    /**
     * The first passage id named in the rendered map, quoted back to the model as a concrete example of
     * what a read looks like. Nothing is inferred from it beyond the shape of an id.
     *
     * @param string[] $blocks
     */
    private function firstUnitId(array $blocks): string
    {
        foreach ($blocks as $block) {
            if (preg_match('/\[(S\d+-C\d+)\]/', $block, $m) === 1) {
                return $m[1];
            }
        }

        return 'S1-C1';
    }

    private function noEvidence(string $query): string
    {
        return "SESSION EVIDENCE — no retained evidence in this conversation matches \"{$query}\". "
            . 'The requested information is not available locally; escalate to search_web if the user needs it.';
    }
}
