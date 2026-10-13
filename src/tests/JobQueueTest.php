<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cache;
use App\Services\JobQueue;

/**
 * Deterministic coverage for the background job queue. No LLM, no model; Redis only, under its own key prefix
 * (`jobs_test`) so it can never touch the app's live queue, and every key it creates is purged afterwards.
 *
 * What it pins down:
 *   - a job survives enqueue → claim → complete, with its payload intact;
 *   - a claimed job cannot be claimed twice (the atomic ZREM is the only owner);
 *   - a fresh job is claimable immediately (the grace knob exists for probes and tests only);
 *   - the type filter separates arrival work from the app's own work;
 *   - failures requeue with a backoff, then stop at MAX_ATTEMPTS;
 *   - FIFO order is kept, and a job with an expired hash is dropped, not returned.
 *
 * Reclaiming a claim whose owner died is not a queue concern any more — boot does it, from the database
 * (QueueReconciliationTest).
 */
class JobQueueTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private const PREFIX = 'jobs_test';

    public function run(): bool
    {
        $queue = new JobQueue(self::PREFIX);
        $queue->purge();

        $this->runEnqueueAndPayload($queue);
        $this->runClaimIsExclusive($queue);
        $this->runGraceWindow($queue);
        $this->runTypeFilter($queue);
        $this->runFifoOrder($queue);
        $this->runCompleteRemovesFromQueue($queue);
        $this->runFailureBackoffThenPermanent($queue);
        $this->runReleaseWithoutAnAttempt($queue);
        $this->runOpenJobsForSession($queue);
        $this->runExpiredHashDropped($queue);

        $queue->purge();

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

        return empty($this->failures);
    }

    private function runEnqueueAndPayload(JobQueue $queue): void
    {
        echo "\n-- enqueue keeps the job and its payload --\n";
        $id = $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 42, 'row_ids' => [7, 8]], 42);
        $job = $queue->get($id);

        $this->test('job exists right after enqueue', $job !== null);
        $this->testEq('type is preserved', JobQueue::TYPE_ENRICH, (string)($job['type'] ?? ''));
        $this->testEq('state is queued', 'queued', (string)($job['state'] ?? ''));
        $this->testEq('session id is preserved', 42, (int)($job['session_id'] ?? 0));
        $this->testEq('row ids survive the round trip', [7, 8], $job['payload']['row_ids'] ?? null);
        $this->testEq('one job waiting', 1, $queue->size());
    }

    private function runClaimIsExclusive(JobQueue $queue): void
    {
        echo "\n-- a claimed job cannot be claimed twice --\n";
        $queue->purge();
        $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 1], 1);

        $first = $queue->claim('worker-a', 0);
        $second = $queue->claim('worker-b', 0);

        $this->test('first claim wins', $first !== null);
        $this->testEq('claim names the claimant', 'worker-a', (string)($first['claimed_by'] ?? ''));
        $this->testEq('nobody else can take it', null, $second);
        $this->testEq('nothing left pending', 0, $queue->counts()['pending']);
        $this->testEq('one job claimed', 1, $queue->counts()['claimed']);
    }

    private function runGraceWindow(JobQueue $queue): void
    {
        echo "\n-- the grace window holds a fresh job back --\n";
        $queue->purge();
        $queue->enqueue(JobQueue::TYPE_ANSWER, ['session_id' => 9], 9);

        $this->testEq('not claimable inside the grace window', null, $queue->claim('worker-a', 60_000));
        $this->test('claimable once the window has passed', $queue->claim('worker-a', 0) !== null);
    }

    private function runTypeFilter(JobQueue $queue): void
    {
        echo "\n-- the type filter separates the two kinds of work --\n";
        $queue->purge();
        $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 3], 3);

        $this->testEq('an enrich job is invisible to an answer waiter', null, $queue->claim('waiter', 0, JobQueue::TYPE_ANSWER));
        $this->testEq('and still queued afterwards', 1, $queue->size());
        $this->test('the worker sees it', $queue->claim('worker', 0, JobQueue::TYPE_ENRICH) !== null);
    }

    private function runFifoOrder(JobQueue $queue): void
    {
        echo "\n-- oldest first --\n";
        $queue->purge();
        $older = $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 1], 1);
        usleep(5_000);
        $newer = $queue->enqueue(JobQueue::TYPE_ANSWER, ['session_id' => 1], 1);

        $first = $queue->claim('worker', 0);
        $this->testEq('the older job is claimed first', $older, (string)($first['id'] ?? ''));
        $second = $queue->claim('worker', 0);
        $this->testEq('then the newer one', $newer, (string)($second['id'] ?? ''));
    }

    private function runCompleteRemovesFromQueue(JobQueue $queue): void
    {
        echo "\n-- complete leaves nothing behind --\n";
        $queue->purge();
        $id = $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 2], 2);
        $queue->claim('worker', 0);
        $queue->complete($id);

        $this->testEq('nothing pending', 0, $queue->counts()['pending']);
        $this->testEq('nothing claimed', 0, $queue->counts()['claimed']);
        $this->testEq('state is done', 'done', (string)($queue->get($id)['state'] ?? ''));
    }

    private function runFailureBackoffThenPermanent(JobQueue $queue): void
    {
        echo "\n-- failures requeue with a backoff, then stop --\n";
        $queue->purge();
        // Backoff 0 / two attempts, so the retry path is exercised without the suite sleeping.
        $quick = new JobQueue(self::PREFIX, 0, 2);
        $id = $quick->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 4], 4);

        $quick->claim('worker', 0);
        $quick->fail($id, 'condenser unreachable');
        $this->testEq('state back to queued', 'queued', (string)($quick->get($id)['state'] ?? ''));
        $this->testEq('attempt counted', 1, (int)($quick->get($id)['attempts'] ?? 0));
        $this->testEq('waiting again', 1, $quick->counts()['pending']);
        $this->test('claimable again', $quick->claim('worker', 0) !== null);

        $quick->fail($id, 'condenser unreachable');
        $this->testEq('state is failed after the attempts run out', 'failed', (string)($quick->get($id)['state'] ?? ''));
        $this->testEq('and it is out of the queue', 0, $quick->counts()['pending']);
        $this->testEq('and nothing is claimed', 0, $quick->counts()['claimed']);
        $this->testEq('and it cannot be claimed again', null, $quick->claim('worker', 0));
    }

    private function runReleaseWithoutAnAttempt(JobQueue $queue): void
    {
        echo "\n-- backing off does not count as a failure --\n";
        $queue->purge();
        $id = $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 7], 7);
        $queue->claim('worker', 0);
        $queue->release($id);

        $this->testEq('no attempt was counted', 0, (int)($queue->get($id)['attempts'] ?? 0));
        $this->testEq('back in the queue', 1, $queue->counts()['pending']);
        $this->testEq('and nothing is claimed', 0, $queue->counts()['claimed']);
        $this->test('immediately claimable', $queue->claim('worker', 0) !== null);
    }

    private function runOpenJobsForSession(JobQueue $queue): void
    {
        echo "\n-- open jobs are listed per conversation --\n";
        $queue->purge();
        $mine = $queue->enqueue(JobQueue::TYPE_ENRICH, ['row_ids' => [11]], 100);
        $queue->enqueue(JobQueue::TYPE_ANSWER, ['row_ids' => []], 200);

        $open = $queue->openJobsForSession(100);
        $this->testEq('one pending job for this conversation', 1, count($open['pending']));
        $this->testEq('nothing claimed yet', 0, count($open['claimed']));
        $this->testEq('it is the right job', $mine, (string)($open['pending'][0]['id'] ?? ''));
        $this->testEq('with its payload intact', [11], $open['pending'][0]['payload']['row_ids'] ?? null);
        $other = $queue->openJobsForSession(200);
        $this->testEq('the other conversation sees only its own job', 1, count($other['pending']));
        $this->testEq('and it is the other kind of work', JobQueue::TYPE_ANSWER, (string)($other['pending'][0]['type'] ?? ''));
        $this->testEq('an unknown conversation sees nothing', 0,
            count($queue->openJobsForSession(999)['pending']) + count($queue->openJobsForSession(999)['claimed']));

        $queue->claim('worker', 0);
        $open = $queue->openJobsForSession(100);
        $this->testEq('once claimed it is listed as running', 1, count($open['claimed']));
        $this->testEq('and no longer pending', 0, count($open['pending']));
    }

    private function runExpiredHashDropped(JobQueue $queue): void
    {
        echo "\n-- an index entry with no job behind it is dropped, not returned --\n";
        $queue->purge();
        $id = $queue->enqueue(JobQueue::TYPE_ENRICH, ['session_id' => 6], 6);
        Cache::getClient()->del('jobs_test:job:' . $id);

        $this->testEq('nothing to claim', null, $queue->claim('worker', 0));
        $this->testEq('the orphan was cleaned out', 0, $queue->counts()['pending']);
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }
}
