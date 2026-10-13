<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\ChatManager;
use App\Logger;
use App\Services\ActiveTurns;
use App\Services\JobQueue;
use App\Services\ModelLock;
use App\Services\PendingPromptQueue;
use App\Services\PipelineWorker;
use App\Services\TurnAnswers;
use App\Services\TurnStream;

/**
 * One turn, over SSE. This action no longer runs a turn: it writes the user's row, queues the turn, and then relays the
 * turn's own stream to this browser (ruling R18). The worker runs the turn, so a browser that walks away is not a stop
 * and costs the turn nothing — only the stop control discards held messages (R8), and a generation that loses its
 * audience is allowed to finish (R14).
 *
 * Two shapes arrive here:
 *
 *   1. an ordinary message — a turn of its own: the row is written, a `turn` job is queued, and this connection becomes
 *      a viewer of the turn's stream;
 *   2. a message sent while the engine is busy (or while this conversation already has messages waiting) — it is
 *      persisted as a held row, appended to the session's pending set, and an answer job is queued; the worker answers
 *      everything held for this conversation as one batch, and this connection watches that through the same stream.
 */
class ChatStreamAction extends BaseAction
{
    /**
     * What the composer's spinner says while this message is waiting for the queue — the state that comes BEFORE the
     * turn's own statuses ("Initializing...", "Analyzing your request…"), and the only place the user should see it.
     * The sidebar pill is about the model (loaded / offline) and never about the queue.
     */
    private const QUEUED_NOTICE = 'It will be your turn soon — your message is queued.';

    /** How long a viewer holds its connection before giving up on a turn. The turn itself is not affected. */
    private const RELAY_SECONDS = 600;

    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(int $sessionId, string $query, $imageFile, $cacheAction, $cacheKey, $activeEditFile = null, ?string $effort = null, ?array $fetchUrls = null): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @set_time_limit(600);

        // Keep running after the client disconnects so AgentManager can detect
        // the abort via connection_aborted() and release the inference lock,
        // instead of PHP killing the script and leaking the lock until TTL.
        ignore_user_abort(true);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $pad = str_repeat(" ", 4096);
        echo ":{$pad}\n\n";
        @flush();

        // This connection is a viewer, not a runner (ruling R18): it hands the turn to the worker and then follows the
        // turn's stream. Nothing below takes the inference lock, and a client that leaves costs the turn nothing.
        $turnStream = new TurnStream();
        $send = function (string $event, array $data = []): void {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        };

        $chatManager = new ChatManager($this->db, $this->agentManager);
        $pending = new PendingPromptQueue();

        try {
            if ($this->shouldHold($sessionId, $pending)) {
                // Held: the engine is busy with the app's own work, or with an earlier batch of this conversation.
                $rowId = $this->persistHeldMessage($sessionId, $query, $effort, $activeEditFile, $fetchUrls);
                $pending->append($sessionId, $rowId, $query);
                $adoptedRowIds = [$rowId];
                $this->ensureAnswerJob($sessionId);

                // Where this viewer starts reading the turn stream: NOW. The stream still holds the frames of whatever
                // ran in this conversation last — statuses, tokens, its `done` — and reading those into a bubble that is
                // waiting its turn showed that earlier turn as if it were this message's answer. Taken before the frames
                // below are sent, so nothing published after this point is lost.
                $heldFrom = $turnStream->lastId($sessionId) ?? '0';

                // And if a turn is live in this conversation right now, everything still to arrive on the stream before
                // its `done` is that turn's: the held message's own turn cannot have started (the worker claims nothing
                // while a turn is live, and this message's batch runs only after it ends). Forwarded, one turn's
                // reasoning and answer rendered in two bubbles at once — the first turn writing into the bubble below
                // it, its own and the waiting one, at the same time.
                $skipLiveTurn = in_array($sessionId, ActiveTurns::sessions(), true);

                $send('queued', [
                    'row_id' => $rowId,
                    'held' => $pending->size($sessionId),
                    'message' => self::QUEUED_NOTICE,
                ]);
                Logger::logEvent('message_held', 'Message held while the engine is busy', [
                    'session_id' => $sessionId,
                    'row_id' => $rowId,
                    'held' => $pending->size($sessionId),
                ], 'info', 'ChatStreamAction');
                $send('trace', ['label' => 'App: message held — the assistant is busy; your message joins the queue and will be answered as one batch']);

            } elseif (trim($query) !== '') {
                // A turn of its own: the row is written first so the job can carry its id — the row is the record, the
                // job is only the work — then the turn is queued and a worker started. This request runs nothing, so it
                // cannot lose a race for the engine and has no batch to defend.
                $written = $chatManager->persistUserTurn($sessionId, $query, $imageFile, $effort, $activeEditFile, $fetchUrls);
                $turnStream->reset($sessionId);
                $jobId = $this->enqueueTurn($sessionId, (int)$written['row_id']);
                Logger::logEvent('turn_enqueued', 'Turn queued for the worker', [
                    'session_id' => $sessionId,
                    'job_id' => $jobId,
                    'row_id' => (int)$written['row_id'],
                ], 'info', 'ChatStreamAction');
                $send('trace', ['label' => sprintf('App: turn queued for the worker (row %d)', (int)$written['row_id'])]);

                // The engine (or another job of this session's) is already busy, so this turn will not be next: say so in
                // the spinner, before the turn's own statuses take it over. When nothing is ahead the worker claims the
                // turn immediately and the ordinary statuses are the first thing the user sees — no extra state, and
                // nothing that has to be taken back.
                if ($this->workAhead($sessionId)) {
                    $send('status', ['text' => self::QUEUED_NOTICE]);
                }
            } else {
                $send('done', ['message' => '', 'session_id' => $sessionId]);

                return;
            }

            // Watch the turn. The worker publishes; this loop only forwards. A viewer whose turn was already answered is
            // served the stored row, and a viewer waiting on a turn that is still owed is never handed the previous
            // answer as if it were this one's. A held message starts at the position it was held at ($heldFrom) and skips
            // the tail of whatever turn was already running ($skipLiveTurn); a turn of its own starts at the beginning of
            // its own (reset) stream.
            $this->relayTurn($sessionId, $send, $turnStream, $heldFrom ?? '0', $skipLiveTurn ?? false);
        } catch (\Throwable $e) {
            // Nothing here runs a turn: this catch is about the request's own work — writing the row, queueing the turn,
            // relaying the stream. Ending the request before a terminal event would leave the browser reporting a
            // dropped connection with no cause, so the reason is logged (critical, visible at /logs) and sent too.
            Logger::logEvent('chat_turn_failed', 'Chat request died before completion: ' . $e->getMessage(), [
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
                'at'         => $e->getFile() . ':' . $e->getLine(),
                'session_id' => $sessionId,
                'where'      => __CLASS__,
            ], 'critical', 'ChatStreamAction');

            $send('error', [
                'code'    => 'internal_error',
                'message' => 'The request failed: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . '. Details in /logs.',
            ]);
        }
    }

    /**
     * Is something ahead of this turn in the queue?
     *
     * The engine in somebody's hands, another job being run, or this conversation's own notes still being built — any of
     * those and the worker will not pick this turn up next. Read-only and never a reason to refuse or to hold: the turn
     * is already queued, and the worker claims it as soon as what is in front of it is done.
     */
    private function workAhead(int $sessionId): bool
    {
        try {
            if (ModelLock::availability()['busy']) {
                return true;
            }
            $queue = new JobQueue();
            if ((int)($queue->counts()['claimed'] ?? 0) > 0) {
                return true;
            }

            return $queue->laneBusyForSession($sessionId, 'p1');
        } catch (\Throwable $e) {
            return false;   // a queue that cannot be read is not a reason to show a waiting state
        }
    }

    /**
     * Should this message be held rather than answered now? Two reasons: the engine is busy (the worker is enriching, or
     * another turn is running), or this conversation already has messages waiting — in which case a new one joins the
     * batch instead of jumping the queue.
     */
    private function shouldHold(int $sessionId, PendingPromptQueue $pending): bool
    {
        try {
            if ($pending->size($sessionId) > 0) {
                return true;
            }

            return (bool)ModelLock::availability()['busy'];
        } catch (\Throwable $e) {
            // Redis trouble: treat the engine as free and let the normal path deal with it (it takes the lock properly
            // and reports a real busy state if there is one).
            return false;
        }
    }

    /** Persist the message as a held row: the transcript's record of what the user sent, which the batch references. */
    private function persistHeldMessage(int $sessionId, string $query, ?string $effort = null, ?string $editFile = null, ?array $fetchUrls = null): int
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => 'user',
            'message' => $query,
            'time_note' => \App\Services\PromptAssemblyService::timeBucket(),
            'token_estimate' => (int)(mb_strlen($query) / 4),
            'message_type' => 'queued',
            // Held messages carry the same per-submit choices as an ordinary one: the batch that answers them is a turn
            // like any other, and it must not answer a message sent from the editor with the normal tool surface at the
            // default effort. The row is where those facts live (Schema.php → turn_effort / turn_edit_file /
            // turn_fetch_urls), so the batch reads them without anyone having to carry them through the queue.
            'turn_effort' => $effort,
            'turn_edit_file' => $editFile,
            'turn_fetch_urls' => $fetchUrls === null ? null : json_encode(array_values($fetchUrls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Make sure an answer job exists for this conversation, and start the worker. This is what makes the batch
     * survivable: if this request dies while waiting, the worker still answers it (plan R7/R10).
     */
    private function ensureAnswerJob(int $sessionId): void
    {
        $queue = new JobQueue();
        foreach ($queue->openJobsForSession($sessionId)['pending'] as $job) {
            if (($job['type'] ?? '') === JobQueue::TYPE_ANSWER) {
                PipelineWorker::spawn();

                return;
            }
        }

        try {
            $queue->enqueue(JobQueue::TYPE_ANSWER, ['session_id' => $sessionId], $sessionId);
            PipelineWorker::spawn();
        } catch (\Throwable $e) {
            Logger::logEvent('answer_job_failed', 'Could not queue an answer job: ' . $e->getMessage(), [
                'session_id' => $sessionId,
            ], 'error', 'ChatStreamAction');
        }
    }

    /**
     * Queue a turn and make sure somebody is there to run it.
     *
     * The job carries the user row's id and nothing else: the row is the record — the message, the attachment and the
     * per-submit choices (reasoning effort, the editor's file, the link decision) are all columns on it, and the manager
     * reads them when the turn starts. A copy in the payload would be a second source of truth, and it was a lossy one.
     */
    private function enqueueTurn(int $sessionId, int $userRowId): string
    {
        $queue = new JobQueue();
        $jobId = (string)$queue->enqueue(JobQueue::TYPE_TURN, [
            'session_id' => $sessionId,
            'user_row_id' => $userRowId,
        ], $sessionId);
        PipelineWorker::spawn();

        return $jobId;
    }

    /**
     * Follow a turn's stream and forward every frame to this browser in the shape its reader already parses.
     *
     * This connection owns nothing: if the client leaves, the turn does not notice (ruling R18). Two endings are
     * possible — the turn's own `done`, or a turn that was already answered when this viewer arrived, in which case the
     * stored row is sent once as `done`. A turn that is still owed never gets the previous answer.
     *
     * `$from` is the stream position this viewer starts at. A turn of its own starts at `0` (the request reset the stream
     * before queueing it, so `0` IS this turn). A message that was held does not: the stream still holds the previous
     * turn's frames, and reading them into a bubble that is waiting its turn showed that older turn's statuses — even its
     * answer — as if they were this message's. A held viewer starts at the position the message was held at.
     *
     * `$skipLiveTurn` drops the tail of a turn that is still running in this conversation (see the caller): nothing
     * published before that turn's `done` can belong to this message.
     */
    private function relayTurn(int $sessionId, callable $send, TurnStream $stream, string $from = '0', bool $skipLiveTurn = false): void
    {
        $deadline = microtime(true) + self::RELAY_SECONDS;

        while (microtime(true) < $deadline) {
            if (connection_aborted()) {
                return;
            }

            $entries = $stream->read($sessionId, $from, 200);
            foreach ($entries as $entry) {
                $from = (string)$entry['id'];
                if ($skipLiveTurn) {
                    // Everything before that turn's `done` is its own output, not this message's.
                    if ($entry['event'] === 'done') {
                        $skipLiveTurn = false;
                    }
                    continue;
                }
                // Forwarded exactly as the turn produced it: one frame per chunk. The browser already coalesces its own
                // painting on animation frames, so merging frames here (tried) only made each visible step bigger — the
                // typing went chunky. The relay's job is prompt delivery, not reshaping the stream.
                $send((string)$entry['event'], (array)$entry['data']);
                if ($entry['event'] === 'done') {
                    return;
                }
            }

            // A live turn that went away without a `done` (it failed, or its process died) must not keep this viewer
            // waiting for frames that will never come: the skip ends with the turn it was skipping. Checked only when the
            // stream has gone quiet, so it costs nothing while frames are flowing.
            if ($skipLiveTurn && $entries === [] && !in_array($sessionId, ActiveTurns::sessions(), true)) {
                $skipLiveTurn = false;
            }

            if (!$stream->exists($sessionId)) {
                // Nothing is publishing for this conversation: either the worker has not started yet — the row it is
                // about to answer is still unclosed, so nothing is sent — or the turn is already answered and this
                // viewer arrived afterwards.
                $stored = $this->storedAnswer($sessionId);
                if ($stored !== null) {
                    $send('done', $stored);

                    return;
                }
            }

            // 15 ms: the relay is the path every frame takes to the browser, so this is added latency on each one. Kept
            // short on purpose — the client paints on its own animation frames, and there is nothing to gain by holding
            // frames back here.
            usleep(15_000);
        }
    }

    /**
     * The answer already stored for this conversation's newest turn, or null while that turn is still owed.
     *
     * @return array<string,mixed>|null
     */
    private function storedAnswer(int $sessionId): ?array
    {
        $terminalRowId = (new TurnAnswers($this->db))->storedAnswerRowId($sessionId);
        if ($terminalRowId === null) {
            return null;
        }

        $rows = $this->db->query(
            'SELECT message, source_map, perf_metrics FROM chat_history WHERE id = ?',
            [$terminalRowId]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'message' => (string)$rows[0]['message'],
            'session_id' => $sessionId,
            'message_id' => $terminalRowId,
            'sources' => json_decode((string)($rows[0]['source_map'] ?? '[]'), true) ?: [],
            'perf_metrics' => json_decode((string)($rows[0]['perf_metrics'] ?? 'null'), true),
            'from_stored_row' => true,
        ];
    }
}
