<?php

namespace App\Services\Tools;

use App\Database;
use App\Search\Bm25Retriever;
use App\Search\WebChunk;

/**
 * Session-local retrieval over retained web evidence.
 *
 * Searches ONLY the active backing_chunks of data_fetching rows in the current
 * session. It never performs network access, never invokes search_web, and
 * never persists anything: the result is TRANSIENT — rehydrated chunks are
 * injected for the current turn only, are not promoted to atomic_context, and
 * do not allocate new source IDs. Retrieved chunks keep their original S#-C#
 * provenance; user-visible citations resolve to the original [S#].
 */
class SearchSessionEvidenceTool
{
    private const MAX_RESULTS = 10;

    private Database $db;
    private Bm25Retriever $retriever;

    /** @var string[] source IDs of chunks actually returned by the last execute() */
    private array $lastRetrievedSourceIds = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->retriever = new Bm25Retriever();
    }

    public function execute(array $params, int $sessionId, array $messages, callable $emit, string $originalJson): string
    {
        $this->lastRetrievedSourceIds = [];

        $query = trim((string)($params['query'] ?? ''));
        if ($query === '') {
            return $this->noEvidence('(empty query)');
        }

        $sourceFilter = $params['source_ids'] ?? null;
        if (is_array($sourceFilter)) {
            $sourceFilter = array_values(array_filter(array_map('strval', $sourceFilter)));
            if (empty($sourceFilter)) {
                $sourceFilter = null;
            }
        } else {
            $sourceFilter = null;
        }

        // Load active Context Data rows only (evicted rows are excluded).
        $rows = $this->db->selectSafe('chat_history', [
            'session_id' => $sessionId,
            'message_type' => 'data_fetching',
            'active_context' => 1,
        ]);

        $chunks = [];
        foreach ($rows as $row) {
            $backing = $row['backing_chunks'] ?? null;
            if (empty($backing)) {
                continue;
            }
            $decoded = json_decode($backing, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $entry) {
                if (!is_array($entry) || empty($entry['sourceId']) || empty($entry['chunkId'])) {
                    continue;
                }
                if ($sourceFilter !== null && !in_array($entry['sourceId'], $sourceFilter, true)) {
                    continue;
                }
                $chunks[] = WebChunk::fromArray($entry);
            }
        }

        if (empty($chunks)) {
            return $this->noEvidence($query);
        }

        $ranked = $this->retriever->rankRawWithScores($chunks, $query, $query);
        if (empty($ranked)) {
            return $this->noEvidence($query);
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
        return "SESSION EVIDENCE — retained from earlier web searches in this conversation{$filterNote}:\n\n" . implode("\n\n", $lines);
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

    private function noEvidence(string $query): string
    {
        return "SESSION EVIDENCE — no retained evidence in this conversation matches \"{$query}\". "
            . 'The requested information is not available locally; escalate to search_web if the user needs it.';
    }
}
