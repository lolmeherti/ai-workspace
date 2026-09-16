<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\AgentManager;
use App\ChatManager;
use App\Search\SourceCondenser;
use App\Search\TokenCounter;
use App\Search\EvidenceBuilder;
use App\Search\WebChunk;
use App\Services\PromptAssemblyService;

/**
 * Manual per-source Context Data operations. Drives the Raw / Atomized / Evicted
 * state machine on a single data_fetching row, complementing the automatic
 * backlog atomization in ChatManager.
 *
 * Operations (POST `action=atomize_context`, field `op`):
 *   - atomize     — LLM-condense the raw chunks, return a PREVIEW (no commit).
 *   - re-atomize  — same as atomize (re-run from persisted raw, works even when raw is evicted).
 *   - commit      — write previewed/hand-edited atoms + set raw_evicted = 1.
 *   - edit_raw    — replace retained evidence, clear stale facts, preserve inclusion.
 *   - edit_atoms  — write hand-edited atoms verbatim (no LLM), raw_evicted untouched.
 *   - delete_atoms — null out atoms, raw_evicted untouched.
 *   - evict_raw   — set raw_evicted = 1 (raw kept in DB, atoms untouched).
 *   - restore     — set raw_evicted = 0 (raw + atoms both live again).
 */
class ContextDataAtomizeAction extends BaseAction
{
    public function __construct(private $db, private AgentManager $agent)
    {
    }

    public function execute(): void
    {
        $op = (string)($_POST['op'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0 || $op === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing op or context item id.'], 400);
            return;
        }

        switch ($op) {
            case 'edit_raw':
                $this->editEvidence($id);
                return;
            case 'atomize':
            case 're-atomize':
                $this->previewAtomize($id);
                return;

            case 'commit':
                $this->commit($id);
                return;

            case 'edit_atoms':
                $this->editAtoms($id);
                return;

            case 'delete_atoms':
                $this->deleteAtoms($id);
                return;

            case 'evict_raw':
                $this->setRawEvicted($id, true);
                return;

            case 'restore':
                $this->setRawEvicted($id, false);
                return;

            default:
                $this->jsonResponse(['status' => 'error', 'message' => 'Unknown operation.'], 400);
        }
    }

    /** Load a data_fetching row's persisted chunks (selected first, backing fallback). */
    private function loadRow(int $id): ?array
    {
        $rows = $this->db->query(
            "SELECT id, session_id, message, token_estimate, search_query, selected_chunks, backing_chunks,
                    raw_evicted, atomic_context, atomic_tokens, source_map
             FROM chat_history
             WHERE id = :id AND message_type = 'data_fetching'",
            [':id' => $id]
        );
        return empty($rows) ? null : $rows[0];
    }

    /** Active prompt-token contribution of a data_fetching row (mirrors the frontend's activeTokens and PromptAssemblyService::injectedEvidenceContent). */
    private static function rowActiveTokens(array $row): int
    {
        $raw = (int)($row['raw_evicted'] ?? 0) === 1 ? 0 : (int)($row['token_estimate'] ?? 0);
        $atomic = $row['atomic_context'] ?? null;
        $hasAtoms = $atomic !== null && $atomic !== '' && $atomic !== 'null';
        $atoms = $hasAtoms ? (int)($row['atomic_tokens'] ?? 0) : 0;
        return $raw + $atoms;
    }

    /**
     * Reconcile chat_sessions.context_tokens after an evidence mutation. The stored
     * value is the last prompt's token count; the evidence delta (before vs after
     * contribution) approximates how much that prompt shrinks/grows, so the header
     * meter reflects the change without waiting for the next turn.
     *
     * @return int|null New total, or null when the row has no session.
     */
    private function reconcileContextTokens(array $before, array $after): ?int
    {
        $sessionId = (int)($before['session_id'] ?? 0);
        if ($sessionId <= 0) {
            return null;
        }
        $delta = self::rowActiveTokens($after) - self::rowActiveTokens($before);
        $rows = $this->db->query('SELECT context_tokens FROM chat_sessions WHERE id = :id', [':id' => $sessionId]);
        $current = !empty($rows) ? (int)($rows[0]['context_tokens'] ?? 0) : 0;
        $next = max(0, $current + $delta);
        if ($next !== $current) {
            $this->db->update('chat_sessions', ['context_tokens' => $next], ['id' => $sessionId]);
        }
        return $next;
    }

    /** Manual edits replace both snapshots: deleted text must not return on extraction. */
    private function editEvidence(int $id): void
    {
        $row = $this->loadRow($id);
        if ($row === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Context item not found.'], 404);
            return;
        }
        if (!isset($_POST['base_message']) || $_POST['base_message'] !== ($row['message'] ?? '')) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Evidence changed since you opened it. Copy your edits, then reopen the evidence before saving.'], 409);
            return;
        }
        try {
            $edits = json_decode((string)($_POST['evidence'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($edits) || !array_is_list($edits)) throw new \InvalidArgumentException('Expected a list of evidence sources.');
            $fields = self::editedEvidence($row, $edits);
        } catch (\JsonException | \InvalidArgumentException $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
            return;
        }
        $this->db->update('chat_history', $fields, ['id' => $id, 'message_type' => 'data_fetching']);
        $total = $this->reconcileContextTokens($row, array_merge($row, $fields));
        $this->jsonResponse(['status' => 'success', 'id' => $id, 'total_session_tokens' => $total]);
    }

    /** Build a coherent evidence snapshot without sending manual edits to an LLM. */
    public static function editedEvidence(array $row, array $edits): array
    {
        $parsed = ContextDataViewAction::parseSources($row['message'] ?? '');
        $plain = $parsed === [];
        if ($plain) $parsed = [['id' => 'manual', 'title' => 'Evidence', 'domain' => '']];
        $sources = array_column($parsed, null, 'id');
        $sourceMap = json_decode($row['source_map'] ?? '{}', true) ?: [];
        $templates = [];
        foreach (ChatManager::decodeChunks($row['selected_chunks'] ?? '') as $chunk) {
            $templates[$chunk->sourceId] ??= $chunk;
        }
        $chunks = [];
        $seen = [];
        foreach ($edits as $edit) {
            $id = $edit['id'] ?? null;
            if (!is_string($id) || !isset($sources[$id]) || isset($seen[$id]) || !is_string($edit['text'] ?? null)) {
                throw new \InvalidArgumentException('Invalid evidence source. Reopen the evidence and try again.');
            }
            $seen[$id] = true;
            if (trim($edit['text']) === '') continue;
            $chunk = isset($templates[$id]) ? clone $templates[$id] : WebChunk::fromArray([
                'sourceId' => $id, 'title' => $sources[$id]['title'], 'domain' => $sources[$id]['domain'],
                'url' => $sourceMap[$id]['url'] ?? '', 'finalUrl' => $sourceMap[$id]['url'] ?? '',
            ]);
            $chunk->chunkId = $id . '-C1';
            $chunk->text = $edit['text'];
            $chunks[] = $chunk;
        }
        if (count($seen) !== count($sources)) throw new \InvalidArgumentException('Include every source; clear its text to remove it.');
        $builder = new EvidenceBuilder();
        $message = $plain ? ($edits[0]['text'] ?? '') : $builder->build($chunks);
        $encoded = json_encode($chunks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [
            'message' => $message,
            'selected_chunks' => $encoded,
            'backing_chunks' => $encoded,
            'token_estimate' => $builder->estimateTokens($message),
            'atomic_context' => null,
            'atomic_tokens' => null,
        ];
    }

    /** LLM-condense the row's raw chunks and return a preview (no DB write). */
    private function previewAtomize(int $id): void
    {
        $row = $this->loadRow($id);
        if ($row === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Context item not found.'], 404);
            return;
        }

        $chunks = ChatManager::decodeChunks((string)($row['selected_chunks'] ?? ''));
        if (empty($chunks)) {
            $chunks = ChatManager::decodeChunks((string)($row['backing_chunks'] ?? ''));
        }
        if (empty($chunks)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No raw chunks retained for this source.'], 422);
            return;
        }

        $condenser = new SourceCondenser($this->agent);
        try {
            $claims = $condenser->condenseBatched($chunks, (string)($row['search_query'] ?? ''));
        } catch (\Throwable $e) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Atomization failed: ' . $e->getMessage()], 500);
            return;
        }

        if (empty($claims)) {
            $this->jsonResponse(['status' => 'empty', 'message' => 'No durable facts could be extracted.'], 200);
            return;
        }

        $this->jsonResponse([
            'status' => 'preview',
            'id' => $id,
            'claims' => $claims,
            'raw_tokens' => (int)($row['token_estimate'] ?? 0),
            'atom_tokens' => self::countAtomTokens($claims),
        ]);
    }

    /** Write previewed atoms + set raw_evicted = 1 (the "Done" of the atomize flow). */
    private function commit(int $id): void
    {
        $claims = $this->readClaims();
        if ($claims === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No atoms to commit.'], 400);
            return;
        }

        $this->writeAtoms($id, $claims, 1);
    }

    /** Write hand-edited atoms verbatim (no LLM); raw_evicted untouched. */
    private function editAtoms(int $id): void
    {
        $claims = $this->readClaims();
        if ($claims === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No atoms to save.'], 400);
            return;
        }

        $this->writeAtoms($id, $claims, null);
    }

    /** Null out atoms; raw_evicted untouched (source becomes Raw or Evicted). */
    private function deleteAtoms(int $id): void
    {
        $before = $this->loadRow($id);
        $fields = ['atomic_context' => null, 'atomic_tokens' => null];
        $this->db->update('chat_history', $fields, ['id' => $id]);

        $total = $before !== null ? $this->reconcileContextTokens($before, array_merge($before, $fields)) : null;

        $this->jsonResponse(['status' => 'success', 'id' => $id, 'raw_evicted' => $this->isRawEvicted($id), 'total_session_tokens' => $total]);
    }

    private function setRawEvicted(int $id, bool $evicted): void
    {
        $before = $this->loadRow($id);
        $fields = ['raw_evicted' => $evicted ? 1 : 0];
        $this->db->update('chat_history', $fields, ['id' => $id]);

        $total = $before !== null ? $this->reconcileContextTokens($before, array_merge($before, $fields)) : null;

        $this->jsonResponse(['status' => 'success', 'id' => $id, 'raw_evicted' => $evicted, 'total_session_tokens' => $total]);
    }

    /** Persist a claim set; when $rawEvicted is non-null it also updates raw_evicted. */
    private function writeAtoms(int $id, array $claims, ?int $rawEvicted): void
    {
        $before = $this->loadRow($id);
        $atomTokens = self::countAtomTokens($claims);
        $fields = [
            'atomic_context' => json_encode($claims, JSON_UNESCAPED_UNICODE),
            'atomic_tokens' => $atomTokens,
        ];
        if ($rawEvicted !== null) {
            $fields['raw_evicted'] = $rawEvicted;
        }
        $this->db->update('chat_history', $fields, ['id' => $id]);

        $total = $before !== null ? $this->reconcileContextTokens($before, array_merge($before, $fields)) : null;

        $this->jsonResponse([
            'status' => 'success',
            'id' => $id,
            'atom_tokens' => $atomTokens,
            'raw_evicted' => (bool)($rawEvicted ?? $this->isRawEvicted($id)),
            'total_session_tokens' => $total,
        ]);
    }

    /** Decode + validate the `claims` POST field (JSON array of {source_id, claim}). */
    private function readClaims(): ?array
    {
        $raw = (string)($_POST['claims'] ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $claims = [];
        foreach ($decoded as $c) {
            if (!is_array($c)) {
                continue;
            }
            $sid = trim((string)($c['source_id'] ?? ''));
            $claim = trim((string)($c['claim'] ?? ''));
            if ($sid !== '' && $claim !== '') {
                $claims[] = ['source_id' => $sid, 'claim' => $claim];
            }
        }
        return !empty($claims) ? $claims : null;
    }

    private function isRawEvicted(int $id): bool
    {
        $rows = $this->db->query("SELECT raw_evicted FROM chat_history WHERE id = :id", [':id' => $id]);
        return !empty($rows) && (bool)($rows[0]['raw_evicted'] ?? 0);
    }

    private static function countAtomTokens(array $claims): int
    {
        return (new TokenCounter())->count(PromptAssemblyService::renderAtomLines($claims));
    }
}
