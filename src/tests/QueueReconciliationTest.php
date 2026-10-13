<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database;
use App\Repositories\ChatSessionRepository;
use App\Services\JobQueue;
use App\Services\QueueReconciliation;

/**
 * Deterministic coverage for the boot reconciliation — the piece that makes the queue agree with the database before
 * anything is consumed. No LLM, no worker: it seeds real rows, seeds queue entries (some of them lies), and checks that
 * one run resolves all four mismatches and that a second run changes nothing.
 *
 * Its queue lives under its own key prefix (`jobs_test_reconcile`), so it can never touch the app's live queue.
 */
class QueueReconciliationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private const PREFIX = 'jobs_test_reconcile';
    private const SESSION_ID = 999004099;
    private const MISSING_ROW_ID = 999999999;

    private int $owedRowId = 0;

    public function __construct(private Database $db)
    {
    }

    public function run(): bool
    {
        $queue = new JobQueue(self::PREFIX);
        $queue->purge();
        $this->seed();

        echo "\n-- owed work with no entry is added --\n";
        $report = (new QueueReconciliation($this->db, $queue))->run();
        $turn = $this->jobForRow($queue, $this->owedRowId);
        $this->test('a turn job exists for the owed row', $turn !== null);
        $this->testEq('and it carries the row it answers', $this->owedRowId, (int)($turn['payload']['user_row_id'] ?? 0));
        $this->testEq('and nothing was dropped', 0, $report['dropped']);

        echo "\n-- a second run changes nothing --\n";
        // The queue prefix is this test's own, but the DATABASE is not: a boot reconciliation rebuilds every owed
        // conversation, not just this file's seed. So the assertions are relative — nothing added, nothing dropped,
        // and the open set is exactly what it was.
        $openAfterFirst = count($queue->openJobs());
        $second = (new QueueReconciliation($this->db, $queue))->run();
        $this->testEq('nothing added', 0, $second['added']);
        $this->testEq('nothing dropped', 0, $second['dropped']);
        $this->testEq('nothing reclaimed', 0, $second['reclaimed']);
        $this->testEq('and the open set is unchanged', $openAfterFirst, count($queue->openJobs()));

        echo "\n-- entries the database no longer supports are dropped --\n";
        $ghostTurn = $queue->enqueue(JobQueue::TYPE_TURN, [
            'session_id' => self::SESSION_ID, 'user_row_id' => self::MISSING_ROW_ID, 'effort' => 'medium',
        ], self::SESSION_ID);
        $ghostBatch = $queue->enqueue(JobQueue::TYPE_ANSWER, ['session_id' => self::SESSION_ID], self::SESSION_ID);
        $report = (new QueueReconciliation($this->db, $queue))->run();
        $this->testEq('a turn for a row that does not exist is dropped', null, $this->findJob($queue, $ghostTurn));
        $this->testEq('a batch for a conversation with nothing held is dropped', null, $this->findJob($queue, $ghostBatch));
        $this->testEq('and both are counted', 2, $report['dropped']);

        echo "\n-- a claim whose owner is gone is handed back --\n";
        $claimed = $queue->claim('worker-999999', 0);
        $this->test('the turn job is claimed', $claimed !== null);
        $report = (new QueueReconciliation($this->db, $queue))->run();
        $this->testEq('the dead claim is reclaimed', 1, $report['reclaimed']);
        $this->testEq('and the job is waiting again', 'queued', (string)($queue->get((string)$claimed['id'])['state'] ?? ''));

        echo "\n-- a claim whose owner is alive is left alone --\n";
        $live = $queue->claim('worker-' . getmypid(), 0);
        $this->test('it is claimed by this process', $live !== null);
        $report = (new QueueReconciliation($this->db, $queue))->run();
        $this->testEq('nothing is reclaimed', 0, $report['reclaimed']);
        $this->testEq('and the live claim stands', 'claimed', (string)($queue->get((string)$live['id'])['state'] ?? ''));

        $queue->purge();
        $this->cleanup();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);
        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return $this->failed === 0;
    }

    /** A conversation with one unanswered user message: the plainest thing the database can say is owed. */
    private function seed(): void
    {
        $this->cleanup();
        (new ChatSessionRepository($this->db))->ensureExists(self::SESSION_ID);
        $this->db->insert('chat_history', [
            'session_id' => self::SESSION_ID,
            'role' => 'user',
            'message' => 'A question nobody has answered yet',
            'token_estimate' => 10,
        ]);
        $this->owedRowId = (int)$this->db->getConnection()->lastInsertId();
    }

    private function cleanup(): void
    {
        $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [self::SESSION_ID]);
    }

    /** @return array<string,mixed>|null */
    private function jobForRow(JobQueue $queue, int $rowId): ?array
    {
        foreach ($queue->openJobs() as $job) {
            if ($job['type'] === JobQueue::TYPE_TURN && (int)($job['payload']['user_row_id'] ?? 0) === $rowId) {
                return $job;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findJob(JobQueue $queue, string $id): ?array
    {
        foreach ($queue->openJobs() as $job) {
            if ($job['id'] === $id) {
                return $job;
            }
        }

        return null;
    }

    private function test(string $label, bool $ok): void
    {
        echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n";
        if ($ok) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = ['label' => $label];
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n";
        if ($ok) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            echo '      expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
        }
    }
}
