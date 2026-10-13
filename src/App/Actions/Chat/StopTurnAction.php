<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Cache;
use App\Logger;
use App\Services\PendingPromptQueue;

/**
 * The stop control: one button, two effects, and no third.
 *
 *   1. Drop the messages the user sent while the engine was busy and that nothing has answered yet. Their rows are
 *      flipped from `queued` to `stopped` so the transcript can still show what was attempted (and say it was not
 *      sent) — the record survives, the work does not.
 *   2. Set the turn-stop flag. The running turn reads it in the disconnect check: the request that pressed stop is
 *      aborting its own fetch, and without the flag that looks exactly like a browser that walked away — which must
 *      *not* stop anything (plan R14: a generation that loses its audience finishes).
 *
 * It does not touch the enrichment job. The owner ruled that enrichment is never cancellable.
 *
 * See `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md` (R8, R15).
 */
final class StopTurnAction extends BaseAction
{
    public const FLAG_TTL_SECONDS = 300;

    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing conversation.'], 400);

            return;
        }

        $result = $this->perform($sessionId);
        $this->jsonResponse(['status' => 'success'] + $result);
    }

    /**
     * The work, separated from the response so it can be driven by the deterministic test — `jsonResponse()` exits,
     * which would otherwise end the whole test process.
     *
     * @return array{dropped:int,stopped_rows:array<int>}
     */
    public function perform(int $sessionId): array
    {
        $queue = new PendingPromptQueue();
        $held = $queue->all($sessionId);
        $dropped = $queue->discard($sessionId);

        // Keep the record, drop the work: the bubbles stay visible as "not sent" rather than disappearing.
        $rowIds = array_values(array_filter(array_map(static fn(array $e): int => (int)$e['row_id'], $held)));
        if ($rowIds !== []) {
            $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
            $this->db->query(
                "UPDATE chat_history SET message_type = 'stopped' WHERE session_id = ? AND message_type = 'queued'"
                . " AND id IN ({$placeholders})",
                array_merge([$sessionId], $rowIds)
            );
        }

        Cache::set('turn_stop:' . $sessionId, '1', self::FLAG_TTL_SECONDS);

        // Is a turn in flight for this conversation? If yes, that turn reads the flag and appends the notice itself;
        // if no, nothing ever will, so the notice is written here and the flag is cleared — a flag left behind would
        // stop the user's *next* turn (plan Task 8b, R15).
        $turnInFlight = false;
        try {
            $turnInFlight = Cache::get('turn_running:' . $sessionId) !== null;
        } catch (\Throwable $e) {
            $turnInFlight = false;
        }
        if (!$turnInFlight) {
            $this->db->insert('chat_history', [
                'session_id' => $sessionId,
                'role' => 'assistant',
                'message' => \App\ChatManager::STOP_NOTICE,
                'message_type' => 'text',
                'token_estimate' => (int)ceil(mb_strlen(\App\ChatManager::STOP_NOTICE) / 4),
                'model' => \App\Config::get('LLM_MODEL_NAME', null),
            ]);
            $closingRowId = (int)$this->db->getConnection()->lastInsertId();

            // The closing line is the terminal row for the turn it stops, and the turn it stops is whatever this
            // conversation still owes — derived from the rows, never guessed.
            $owedRows = array_map(
                static fn(array $row): int => (int)$row['row_id'],
                (new \App\Services\OwedWork($this->db))->turns($sessionId)
            );
            if ($owedRows !== []) {
                (new \App\Services\TurnAnswers($this->db))->record(
                    $owedRows,
                    $closingRowId,
                    \App\Services\TurnAnswers::KIND_STOPPED
                );
            }

            Cache::delete('turn_stop:' . $sessionId);
        }

        Logger::logEvent('turn_stop_requested', 'Stop requested by the user', [
            'session_id' => $sessionId,
            'held_messages_dropped' => $dropped,
            'stopped_rows' => $rowIds,
        ], 'info', 'StopTurnAction');

        return ['dropped' => $dropped, 'stopped_rows' => $rowIds];
    }
}
