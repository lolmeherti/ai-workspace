<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\AgentManager;
use App\ChatManager;
use App\Search\SourceCondenser;
use App\Search\TokenCounter;
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
            "SELECT id, message, token_estimate, search_query, selected_chunks, backing_chunks,
                    raw_evicted, atomic_context, atomic_tokens
             FROM chat_history
             WHERE id = :id AND message_type = 'data_fetching'",
            [':id' => $id]
        );
        return empty($rows) ? null : $rows[0];
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
        $this->db->update('chat_history', [
            'atomic_context' => null,
            'atomic_tokens' => null,
        ], ['id' => $id]);

        $this->jsonResponse(['status' => 'success', 'id' => $id, 'raw_evicted' => $this->isRawEvicted($id)]);
    }

    private function setRawEvicted(int $id, bool $evicted): void
    {
        $this->db->update('chat_history', ['raw_evicted' => $evicted ? 1 : 0], ['id' => $id]);
        $this->jsonResponse(['status' => 'success', 'id' => $id, 'raw_evicted' => $evicted]);
    }

    /** Persist a claim set; when $rawEvicted is non-null it also updates raw_evicted. */
    private function writeAtoms(int $id, array $claims, ?int $rawEvicted): void
    {
        $atomTokens = self::countAtomTokens($claims);
        $fields = [
            'atomic_context' => json_encode($claims, JSON_UNESCAPED_UNICODE),
            'atomic_tokens' => $atomTokens,
        ];
        if ($rawEvicted !== null) {
            $fields['raw_evicted'] = $rawEvicted;
        }
        $this->db->update('chat_history', $fields, ['id' => $id]);

        $this->jsonResponse([
            'status' => 'success',
            'id' => $id,
            'atom_tokens' => $atomTokens,
            'raw_evicted' => (bool)($rawEvicted ?? $this->isRawEvicted($id)),
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
