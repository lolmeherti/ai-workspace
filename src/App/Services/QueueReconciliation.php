<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

/**
 * Boot is what makes the queue agree with the database.
 *
 * The queue is an index, not a record: the facts live in `chat_history` and `turn_answers`, and what is owed is a query
 * against them (`OwedWork`). Anything the queue holds that the database no longer supports is dropped; anything the
 * database says is owed with nothing in the queue is added; and every claim found at boot belongs to a process that may
 * not exist any more, so each one is checked against its owner's pid and handed back when that owner is gone. Run it
 * twice and the second run changes nothing.
 *
 * Nothing here uses a clock. A claim is not "old" — it is either owned by a living process or it is not, and the
 * database decides the rest.
 */
final class QueueReconciliation
{
    public function __construct(private Database $db, private JobQueue $queue)
    {
    }

    /**
     * @return array{dropped:int,added:int,reclaimed:int,kept:int}
     */
    public function run(): array
    {
        $owed = new OwedWork($this->db);
        [$owedKeys, $toAdd] = $this->facts($owed);

        $dropped = 0;
        $reclaimed = 0;
        $kept = 0;
        $covered = [];

        foreach ($this->queue->openJobs() as $job) {
            $keys = $this->factKeysOf($job);
            $matches = array_values(array_filter($keys, static fn(string $key): bool => isset($owedKeys[$key])));

            if ($keys !== [] && $matches === []) {
                // The database no longer says this work is owed: a row that got answered (or deleted), a session that
                // went away, a batch that was already taken. The entry is not "old" — it is wrong.
                $this->queue->drop((string)$job['id']);
                $dropped++;

                continue;
            }
            if ($keys === []) {
                // A type this build does not know how to match. Leave it alone: dropping work you cannot understand is
                // how a queue loses the one job that mattered.
                $kept++;
                Logger::logEvent('queue_reconcile_unknown_job', 'Job of an unknown type left untouched at boot', [
                    'job_id' => (string)$job['id'], 'type' => (string)$job['type'],
                ], 'warn', 'QueueReconciliation');

                continue;
            }

            foreach ($matches as $key) {
                $covered[$key] = true;
            }
            $kept++;

            // A claim whose owner process is gone is nobody's work: hand it back. The fact is still owed, so the job
            // itself stays — only its claim is given up. (A worker started inside a container that has since restarted
            // leaves exactly this behind.)
            if (($job['index'] ?? '') === 'claimed' && !$this->ownerAlive((string)($job['claimed_by'] ?? ''))) {
                $this->queue->release((string)$job['id']);
                $reclaimed++;
            }
        }

        $added = 0;
        foreach ($toAdd as $entry) {
            $keys = array_values(array_filter(
                $entry['keys'],
                static fn(string $key): bool => !isset($covered[$key])
            ));
            if ($keys === []) {
                continue;
            }
            if ($entry['type'] === JobQueue::TYPE_ENRICH) {
                // One enrichment job per conversation, carrying only the rows nobody has picked up.
                $rowIds = array_map(static fn(string $key): int => (int)substr($key, strlen('enrich-')), $keys);
                $this->queue->enqueue($entry['type'], ['session_id' => $entry['session_id'], 'row_ids' => $rowIds], $entry['session_id']);
            } else {
                $this->queue->enqueue($entry['type'], $entry['payload'], $entry['session_id']);
            }
            $added++;
        }

        $report = ['dropped' => $dropped, 'added' => $added, 'reclaimed' => $reclaimed, 'kept' => $kept];
        Logger::logEvent('queue_reconciled', 'Queue rebuilt from the database at boot', $report, 'info', 'QueueReconciliation');

        return $report;
    }

    /**
     * What the database says is owed, as (a) a set of fact keys the queue can be matched against, and (b) the jobs that
     * would carry each fact — oldest row first, so a recovered queue consumes in the same order a fresh one would.
     *
     * @return array{0:array<string,bool>,1:array<int,array{type:string,session_id:int,payload:array<string,mixed>,keys:array<int,string>}>}
     */
    private function facts(OwedWork $owed): array
    {
        $keys = [];
        $toAdd = [];

        foreach ($owed->turns() as $turn) {
            $key = 'turn-' . $turn['row_id'];
            $keys[$key] = true;
            $toAdd[] = [
                'type' => JobQueue::TYPE_TURN,
                'session_id' => $turn['session_id'],
                'keys' => [$key],
                'payload' => [
                    'session_id' => $turn['session_id'],
                    'user_row_id' => $turn['row_id'],
                    // Nothing else: the per-submit choices (effort, editor file, link decision) are columns on that row,
                    // so a recovered turn runs exactly like the message that was never answered — instead of being
                    // guessed at with defaults. See
                    // .hermes/plans/tools/2026-10-12_tool-lifecycle-and-queue-integrity.md.
                ],
            ];
        }

        $enrichBySession = [];
        foreach ($owed->atomization() as $row) {
            $key = 'enrich-' . $row['row_id'];
            $keys[$key] = true;
            $enrichBySession[$row['session_id']][] = $key;
        }
        foreach ($enrichBySession as $sessionId => $rowKeys) {
            $toAdd[] = [
                'type' => JobQueue::TYPE_ENRICH,
                'session_id' => (int)$sessionId,
                'keys' => $rowKeys,
                'payload' => [],
            ];
        }

        foreach ($owed->batches() as $row) {
            $key = 'answer-' . $row['session_id'];
            if (isset($keys[$key])) {
                continue; // one batch job per conversation, however many held rows it has
            }
            $keys[$key] = true;
            $toAdd[] = [
                'type' => JobQueue::TYPE_ANSWER,
                'session_id' => $row['session_id'],
                'keys' => [$key],
                'payload' => ['session_id' => $row['session_id']],
            ];
        }

        return [$keys, $toAdd];
    }

    /**
     * The fact keys a job carries. An enrichment job carries one key per row it means to work on; a turn carries its
     * user row; a batch carries its conversation.
     *
     * @param array<string,mixed> $job
     * @return array<int,string>
     */
    private function factKeysOf(array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        return match ((string)($job['type'] ?? '')) {
            JobQueue::TYPE_TURN => isset($payload['user_row_id']) ? ['turn-' . (int)$payload['user_row_id']] : [],
            JobQueue::TYPE_ENRICH => array_values(array_map(
                static fn(mixed $id): string => 'enrich-' . (int)$id,
                (array)($payload['row_ids'] ?? [])
            )),
            JobQueue::TYPE_ANSWER => isset($payload['session_id']) ? ['answer-' . (int)$payload['session_id']] : [],
            default => [],
        };
    }

    /**
     * Is the process that took this claim still there? Workers are named `worker-<pid>` / `<name>-<pid>`, so the pid is
     * in the name and /proc answers the question. A name without a pid cannot be vouched for, so its claim is given up.
     */
    private function ownerAlive(string $claimedBy): bool
    {
        if (preg_match('/-(\d+)$/', $claimedBy, $m) !== 1) {
            return false;
        }

        return is_dir('/proc/' . (int)$m[1]);
    }
}
