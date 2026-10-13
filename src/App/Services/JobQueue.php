<?php

declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Logger;
use Predis\Client;

/**
 * The background job queue, in LANES — because the work in it is not equal.
 *
 * Three things can want the engine, and the owner ruled on the order (2026-10-11):
 *
 *   1. A USER'S TURN (`turn`) — the loop, its tool rounds and the answer that follows them. It is a job like any other
 *      now: a request writes its row and subscribes to the turn's stream, and the worker runs it (agent task 4 /
 *      ruling R1). It is the highest lane, so the app's own work never makes a user's turn wait.
 *   2. ATOMIZATION (`enrich`) — building a fetched source's notes and contents index. Required for the data model, so
 *      it outranks answering and is uncancellable, but it waits for the turn to end: it is the app's own work, and
 *      starting it mid-turn is what stalled a turn between its tool call and its answer ("the ai cant do work because
 *      the atomization is pending").
 *   3. ANSWERING HELD MESSAGES (`answer`) — the lowest lane. It is also the one that must wait for lane 2 of ITS
 *      OWN conversation: answering from a store whose notes are not built yet is the very thing the queue exists to
 *      avoid, and the two used to interleave because they shared one FIFO index (`answer` jobs are enqueued earlier —
 *      when a message is held — so they jumped ahead of the `enrich` job created when the turn ended).
 *
 * So: one sorted-set pair per lane (`pending:turn` / `claimed:turn`, and the same for p1 and p2), claimed strictly in
 * lane order, plus one hash per job carrying its lane. Two workers still cannot run the same job: the claim stays a
 * read-then-atomic-remove, where ZREM decides the winner.
 *
 * See `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
 */
final class JobQueue
{
    public const TYPE_TURN = 'turn';
    public const TYPE_ENRICH = 'enrich';
    public const TYPE_ANSWER = 'answer';

    /**
     * Lane names, highest priority first. The order of this array IS the claim order.
     *
     * `turn` is a whole model turn — the loop, its rounds and its answer (agent task 4 / ruling R1). It outranks the
     * app's own work, so a user's turn is never made to wait on notes being built.
     */
    public const LANES = ['turn', 'p1', 'p2'];

    private const MAX_ATTEMPTS = 3;
    // No TTL on a job's hash, and no TTL on its index entry. Nothing here expires work: the database says what is owed,
    // boot rebuilds the queue from it (QueueReconciliation), and a timer that deletes work is the one failure mode this
    // design exists to remove. A finished or failed job's hash stays until the next reconciliation drops it.
    private const BACKOFF_MS = 2_000;
    private const BACKOFF_CAP_MS = 30_000;

    /**
     * @param string $prefix      Key namespace. The deterministic test runs against its own prefix so it never
     *                            collides with the app's live queue.
     * @param int    $backoffMs   Wait before a failed job is retried; injectable so the test does not sleep.
     * @param int    $maxAttempts Attempts before a job is left failed.
     */
    public function __construct(
        private string $prefix = 'jobs',
        private int $backoffMs = self::BACKOFF_MS,
        private int $maxAttempts = self::MAX_ATTEMPTS,
    ) {
    }

    /** Which lane a job type belongs to. Unknown types land in the lowest lane rather than on the floor. */
    public static function laneFor(string $type): string
    {
        return match ($type) {
            self::TYPE_TURN => 'turn',
            self::TYPE_ENRICH => 'p1',
            default => 'p2',
        };
    }

    /**
     * Queue a job. Returns its id; the id is what the log and the status surface report.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $type, array $payload, int $sessionId): string
    {
        $redis = $this->redis();
        $id = bin2hex(random_bytes(8));
        $now = $this->nowMs();
        $lane = self::laneFor($type);

        $redis->hset($this->jobKey($id), 'id', $id);
        $redis->hset($this->jobKey($id), 'type', $type);
        $redis->hset($this->jobKey($id), 'lane', $lane);
        $redis->hset($this->jobKey($id), 'session_id', (string)$sessionId);
        $redis->hset($this->jobKey($id), 'payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $redis->hset($this->jobKey($id), 'state', 'queued');
        $redis->hset($this->jobKey($id), 'attempts', '0');
        $redis->hset($this->jobKey($id), 'created_at', gmdate('c'));
        $redis->hset($this->jobKey($id), 'created_ms', (string)$now);

        $redis->zadd($this->indexKey($lane, 'pending'), [$id => $now]);

        Logger::logEvent('job_enqueued', "Job queued: {$type}", [
            'job_id' => $id,
            'type' => $type,
            'lane' => $lane,
            'session_id' => $sessionId,
            'payload' => $payload,
        ], 'info', 'JobQueue::enqueue');

        SessionState::publish($sessionId);

        return $id;
    }

    /**
     * Claim the oldest claimable job, highest lane first.
     *
     * @param string      $workerId who is claiming (a worker name, or the request's own identifier)
     * @param int         $graceMs  a test/probe knob only — production claims immediately
     * @param string|null $onlyType restrict to one job type
     * @return array<string,mixed>|null the job, with claim fields merged in
     */
    public function claim(string $workerId, int $graceMs = 0, ?string $onlyType = null): ?array
    {
        $lanes = $onlyType !== null ? [self::laneFor($onlyType)] : self::LANES;
        foreach ($lanes as $lane) {
            $job = $this->claimFromLane($lane, $workerId, $graceMs, $onlyType);
            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function claimFromLane(string $lane, string $workerId, int $graceMs, ?string $onlyType): ?array
    {
        $redis = $this->redis();
        $now = $this->nowMs();
        $oldest = $now - $graceMs;

        $candidates = $redis->zrangebyscore($this->indexKey($lane, 'pending'), '-inf', (string)$oldest, ['limit' => [0, 25]]);
        foreach ($candidates as $id) {
            $id = (string)$id;
            $job = $this->get($id);
            if ($job === null) {
                // The hash expired but the index entry survived: drop the orphan and look at the next candidate.
                $redis->zrem($this->indexKey($lane, 'pending'), $id);
                continue;
            }
            if ($onlyType !== null && (string)($job['type'] ?? '') !== $onlyType) {
                continue;
            }
            if ((int)$redis->zrem($this->indexKey($lane, 'pending'), $id) !== 1) {
                // Another process claimed it between the read and the remove.
                continue;
            }

            $redis->zadd($this->indexKey($lane, 'claimed'), [$id => $now]);
            $redis->hset($this->jobKey($id), 'state', 'claimed');
            $redis->hset($this->jobKey($id), 'claimed_by', $workerId);
            $redis->hset($this->jobKey($id), 'claimed_at', gmdate('c'));
            $redis->hset($this->jobKey($id), 'claimed_ms', (string)$now);
            // The state moved: a page watching this conversation should see the work start, not wait for a poll.
            SessionState::publish((int)($job['session_id'] ?? 0));

            // Scope this one line to the job's conversation: at claim time the ambient session is whatever the caller
            // had (nothing, in a worker), and a session-less claim event is invisible to every per-session view.
            $previousSession = Logger::currentSessionId();
            $jobSession = (int)($job['session_id'] ?? 0);
            Logger::setSessionId($jobSession > 0 ? $jobSession : null);
            Logger::logEvent('job_claimed', "Job claimed: {$job['type']}", [
                'job_id' => $id,
                'type' => $job['type'],
                'lane' => $lane,
                'session_id' => $jobSession,
                'worker' => $workerId,
            ], 'info', 'JobQueue::claim');
            Logger::setSessionId($previousSession);

            $job['state'] = 'claimed';
            $job['lane'] = $lane;
            $job['claimed_by'] = $workerId;
            $job['claimed_ms'] = $now;

            return $job;
        }

        return null;
    }

    /** Mark a job finished. The hash is kept for a while so the status surface can still report it. */
    public function complete(string $id): void
    {
        $redis = $this->redis();
        $lane = $this->laneOf($id);
        $redis->zrem($this->indexKey($lane, 'claimed'), $id);
        $redis->hset($this->jobKey($id), 'state', 'done');
        $redis->hset($this->jobKey($id), 'finished_at', gmdate('c'));

        Logger::logEvent('job_done', 'Job finished', ['job_id' => $id], 'info', 'JobQueue::complete');
        SessionState::publish((int)($job['session_id'] ?? 0));
    }

    /**
     * Hand a claim straight back to the queue without counting an attempt. This is not a failure: it is how a worker
     * backs off when the inference lock is taken by someone else, how it defers to a live turn, and how it waits for
     * the higher lane to finish. Counting these as failures would let three busy moments permanently kill a job.
     */
    public function release(string $id): void
    {
        $redis = $this->redis();
        $job = $this->get($id);
        if ($job === null) {
            return;
        }
        $lane = (string)($job['lane'] ?? self::laneFor((string)($job['type'] ?? '')));
        $redis->zrem($this->indexKey($lane, 'claimed'), $id);
        $redis->hset($this->jobKey($id), 'state', 'queued');
        $redis->zadd($this->indexKey($lane, 'pending'), [$id => $this->nowMs()]);

        Logger::logEvent('job_released', 'Job handed back without an attempt', ['job_id' => $id], 'info', 'JobQueue::release');
        SessionState::publish((int)($job['session_id'] ?? 0));
    }

    /**
     * Record a failure. The job is requeued with a short backoff until MAX_ATTEMPTS, then left failed — a job that
     * cannot run must not spin, and the rows it would have enriched stay for the turn-start backstop (plan R12).
     */
    public function fail(string $id, string $error): void
    {
        $redis = $this->redis();
        $job = $this->get($id) ?? [];
        $lane = (string)($job['lane'] ?? self::laneFor((string)($job['type'] ?? '')));
        $attempts = (int)($job['attempts'] ?? 0) + 1;
        $redis->zrem($this->indexKey($lane, 'claimed'), $id);
        $redis->hset($this->jobKey($id), 'attempts', (string)$attempts);
        $redis->hset($this->jobKey($id), 'error', mb_substr($error, 0, 500));

        if ($attempts < $this->maxAttempts) {
            $backoff = min(self::BACKOFF_CAP_MS, $this->backoffMs * $attempts);
            $redis->hset($this->jobKey($id), 'state', 'queued');
            $redis->zadd($this->indexKey($lane, 'pending'), [$id => $this->nowMs() + $backoff]);
            Logger::logEvent('job_requeued', 'Job failed; retrying', [
                'job_id' => $id, 'attempts' => $attempts, 'backoff_ms' => $backoff, 'error' => $error,
            ], 'warn', 'JobQueue::fail');
            SessionState::publish((int)($job['session_id'] ?? 0));

            return;
        }

        // Give up: out of BOTH indexes of its lane. A job that is already waiting in `pending` from an earlier retry
        // must be removed there too, or it would sit in the queue forever with a `failed` state (caught by phase 48).
        $redis->zrem($this->indexKey($lane, 'pending'), $id);
        $redis->hset($this->jobKey($id), 'state', 'failed');
        $redis->hset($this->jobKey($id), 'finished_at', gmdate('c'));
        Logger::logEvent('job_failed', 'Job failed permanently', [
            'job_id' => $id, 'attempts' => $attempts, 'error' => $error,
        ], 'error', 'JobQueue::fail');
        SessionState::publish((int)($job['session_id'] ?? 0));
    }

    /**
     * Every open job, in one list — both indexes of every lane. The boot reconciliation needs the whole picture (what
     * the queue thinks is owed, before it compares it with what the database says), and walking the indexes is the only
     * way to get it: a job's own hash says nothing about whether the queue still holds it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openJobs(): array
    {
        $redis = $this->redis();
        $jobs = [];
        foreach (['pending', 'claimed'] as $index) {
            foreach (self::LANES as $lane) {
                foreach ($redis->zrange($this->indexKey($lane, $index), 0, -1) as $id) {
                    $id = (string)$id;
                    $job = $this->get($id);
                    if ($job === null) {
                        continue;
                    }
                    $jobs[] = [
                        'id' => $id,
                        'type' => (string)($job['type'] ?? ''),
                        'lane' => (string)($job['lane'] ?? self::laneFor((string)($job['type'] ?? ''))),
                        'state' => (string)($job['state'] ?? ''),
                        'index' => $index,
                        'payload' => is_array($job['payload'] ?? null) ? $job['payload'] : [],
                        'claimed_by' => (string)($job['claimed_by'] ?? ''),
                    ];
                }
            }
        }

        return $jobs;
    }

    /**
     * Drop a job as though it had never been enqueued: out of both indexes, hash and all. This is for work the database
     * says is no longer owed — not for a job that failed, which keeps its hash so its attempts stay visible.
     */
    public function drop(string $id): void
    {
        $redis = $this->redis();
        $lane = $this->laneOf($id);
        $sessionId = (int)($this->get($id)['session_id'] ?? 0);
        $redis->zrem($this->indexKey($lane, 'claimed'), $id);
        $redis->zrem($this->indexKey($lane, 'pending'), $id);
        $redis->del($this->jobKey($id));

        Logger::logEvent('job_dropped', 'Job dropped: the database no longer says this work is owed', [
            'job_id' => $id, 'lane' => $lane,
        ], 'info', 'JobQueue::drop');
        SessionState::publish($sessionId);
    }

    /** @return array<string,mixed>|null a job's fields, payload decoded */
    public function get(string $id): ?array
    {
        $fields = $this->redis()->hgetall($this->jobKey($id));
        if (empty($fields)) {
            return null;
        }
        $payload = json_decode((string)($fields['payload'] ?? ''), true);
        $fields['payload'] = is_array($payload) ? $payload : [];

        return $fields;
    }

    /**
     * Is there work in a lane waiting or running for this conversation? This is the gate that keeps lane 2 of a
     * conversation behind lane 1 of the same conversation — atomization first, then the messages that piled up while
     * the engine was busy (the owner's steps 2 and 3).
     */
    public function laneBusyForSession(int $sessionId, string $lane = 'p1'): bool
    {
        $redis = $this->redis();
        foreach (['pending', 'claimed'] as $index) {
            foreach ($redis->zrange($this->indexKey($lane, $index), 0, -1) as $id) {
                $job = $this->get((string)$id);
                if ($job !== null && (int)($job['session_id'] ?? 0) === $sessionId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{pending:int,claimed:int,lanes:array<string,array{pending:int,claimed:int}>} */
    public function counts(): array
    {
        $redis = $this->redis();
        $lanes = [];
        $pending = 0;
        $claimed = 0;
        foreach (self::LANES as $lane) {
            $p = (int)$redis->zcard($this->indexKey($lane, 'pending'));
            $c = (int)$redis->zcard($this->indexKey($lane, 'claimed'));
            $lanes[$lane] = ['pending' => $p, 'claimed' => $c];
            $pending += $p;
            $claimed += $c;
        }

        return ['pending' => $pending, 'claimed' => $claimed, 'lanes' => $lanes];
    }

    /** Jobs waiting to be claimed, across all lanes. */
    public function size(): int
    {
        return $this->counts()['pending'];
    }

    /**
     * Open jobs (queued or claimed) for one conversation. The status surface reads this so the UI can say "the app is
     * still working on this conversation" without scanning job hashes itself. Cheap by construction: it walks the lane
     * indexes, which hold only jobs that are in flight.
     *
     * @return array{pending:array<int,array<string,mixed>>,claimed:array<int,array<string,mixed>>}
     */
    public function openJobsForSession(int $sessionId): array
    {
        $redis = $this->redis();
        $open = ['pending' => [], 'claimed' => []];
        foreach (['pending', 'claimed'] as $index) {
            foreach (self::LANES as $lane) {
                foreach ($redis->zrange($this->indexKey($lane, $index), 0, -1) as $id) {
                    $job = $this->get((string)$id);
                    if ($job === null || (int)($job['session_id'] ?? 0) !== $sessionId) {
                        continue;
                    }
                    $open[$index][] = [
                        'id' => (string)$id,
                        'type' => (string)($job['type'] ?? ''),
                        'lane' => (string)($job['lane'] ?? self::laneFor((string)($job['type'] ?? ''))),
                        'state' => (string)($job['state'] ?? ''),
                        'payload' => is_array($job['payload'] ?? null) ? $job['payload'] : [],
                    ];
                }
            }
        }

        return $open;
    }

    /** Remove every key this queue owns — used by the deterministic test, never by the app. */
    public function purge(): void
    {
        $redis = $this->redis();
        foreach (self::LANES as $lane) {
            foreach (['pending', 'claimed'] as $index) {
                foreach ($redis->zrange($this->indexKey($lane, $index), 0, -1) as $id) {
                    $redis->del($this->jobKey((string)$id));
                }
            }
            $redis->del([$this->indexKey($lane, 'pending'), $this->indexKey($lane, 'claimed')]);
        }
    }

    /** A job's own lane, read back from its hash (falls back to the type's lane for rows written before lanes). */
    private function laneOf(string $id): string
    {
        $job = $this->get($id) ?? [];

        return (string)($job['lane'] ?? self::laneFor((string)($job['type'] ?? '')));
    }

    private function indexKey(string $lane, string $index): string
    {
        return $this->key($index . ':' . $lane);
    }

    private function key(string $suffix): string
    {
        return $this->prefix . ':' . $suffix;
    }

    private function jobKey(string $id): string
    {
        return $this->key('job:' . $id);
    }

    private function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private function redis(): Client
    {
        return Cache::getClient();
    }
}
