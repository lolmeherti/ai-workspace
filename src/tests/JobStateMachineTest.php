<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enums\JobState;
use App\Enums\JobHistoryReason;
use App\Jobs\JobStateMachine;

class JobStateMachineTest
{
    private JobStateMachine $machine;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct()
    {
        $this->machine = new JobStateMachine();
    }

    public function run(): bool
    {
        $this->runTransitions();
        $this->runHistoryRequirements();
        $this->runRestore();

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

    private function runTransitions(): void
    {
        echo "\n=== transitions ===\n";

        $job = $this->machine->transition($this->baseJob(), JobState::INTERESTED, null, '2026-08-01 10:00:00');
        $this->testEq('unread -> interested sets state', 'interested', $job['state']);
        $this->testEq('interested_at recorded', '2026-08-01 10:00:00', $job['interested_at']);
        $this->test('history_reason stays null on non-history', $job['history_reason'] === null);
        $this->testEq('one transition appended', 1, count($this->transitions($job)));

        $job = $this->machine->transition($job, JobState::APPLIED, null, '2026-08-02 11:00:00');
        $this->testEq('interested -> applied sets state', 'applied', $job['state']);
        $this->testEq('applied_at uses provided timestamp', '2026-08-02 11:00:00', $job['applied_at']);
        $this->testEq('two transitions accumulated', 2, count($this->transitions($job)));

        $job = $this->machine->transition($job, JobState::INTERVIEW, null, '2026-08-03 09:00:00');
        $this->testEq('applied -> interview sets state', 'interview', $job['state']);
        $this->testEq('interview_at recorded', '2026-08-03 09:00:00', $job['interview_at']);

        $job = $this->machine->transition($job, JobState::OFFER, null, '2026-08-04 12:00:00');
        $this->testEq('interview -> offer sets state', 'offer', $job['state']);
        $this->testEq('offer_at recorded', '2026-08-04 12:00:00', $job['offer_at']);

        $this->test('illegal applied -> unread throws', $this->throwsInvalid(function () {
            $this->machine->transition($this->baseJob(['state' => 'applied']), JobState::UNREAD);
        }));

        $this->test('illegal offer -> interested throws', $this->throwsInvalid(function () {
            $this->machine->transition($this->baseJob(['state' => 'offer']), JobState::INTERESTED);
        }));

        $this->test('history has no outgoing transitions', $this->throwsInvalid(function () {
            $this->machine->transition($this->baseJob(['state' => 'history']), JobState::OFFER);
        }));
    }

    private function runHistoryRequirements(): void
    {
        echo "\n=== history requirements ===\n";

        $this->test('unread -> history without reason throws', $this->throwsInvalid(function () {
            $this->machine->transition($this->baseJob(), JobState::HISTORY);
        }));

        $job = $this->machine->transition($this->baseJob(), JobState::HISTORY, JobHistoryReason::NOT_INTERESTED, '2026-08-05 08:00:00');
        $this->testEq('unread -> history sets state', 'history', $job['state']);
        $this->testEq('history_reason stored', 'not_interested', $job['history_reason']);
        $this->testEq('history_at recorded', '2026-08-05 08:00:00', $job['history_at']);

        $job = $this->machine->transition($this->baseJob(['state' => 'applied']), JobState::HISTORY, JobHistoryReason::REJECTED_BY_COMPANY, '2026-08-05 08:00:00');
        $this->testEq('applied -> history stores rejected reason', 'rejected_by_company', $job['history_reason']);

        $this->test('interested -> history requires reason', $this->throwsInvalid(function () {
            $this->machine->transition($this->baseJob(['state' => 'interested']), JobState::HISTORY);
        }));
    }

    private function runRestore(): void
    {
        echo "\n=== restore ===\n";

        $job = $this->baseJob();
        $job = $this->machine->transition($job, JobState::INTERESTED, null, '2026-08-01 10:00:00');
        $job = $this->machine->transition($job, JobState::APPLIED, null, '2026-08-02 11:00:00');
        $job = $this->machine->transition($job, JobState::HISTORY, JobHistoryReason::REJECTED_BY_COMPANY, '2026-08-05 08:00:00');
        $before = count($this->transitions($job));

        $restored = $this->machine->restore($job);
        $this->testEq('rejected after applied restores to applied', 'applied', $restored['state']);
        $this->test('history_reason cleared on restore', $restored['history_reason'] === null);
        $this->test('history_at cleared on restore', $restored['history_at'] === null);
        $this->testEq('applied_at preserved on restore', '2026-08-02 11:00:00', $restored['applied_at']);
        $this->testEq('restore appends a transition', $before + 1, count($this->transitions($restored)));

        $this->test('restore on non-history throws', $this->throwsInvalid(function () {
            $this->machine->restore($this->baseJob(['state' => 'applied']));
        }));

        $unreadJob = $this->machine->transition($this->baseJob(), JobState::HISTORY, JobHistoryReason::NOT_INTERESTED, '2026-08-05 08:00:00');
        $restoredUnread = $this->machine->restore($unreadJob);
        $this->testEq('not interested restores to unread', 'unread', $restoredUnread['state']);
    }

    private function baseJob(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'job-1',
            'state' => 'unread',
            'state_timestamps' => null,
            'history_reason' => null,
            'interested_at' => null,
            'applied_at' => null,
            'interview_at' => null,
            'offer_at' => null,
            'history_at' => null,
        ], $overrides);
    }

    private function transitions(array $job): array
    {
        $decoded = json_decode($job['state_timestamps'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function throwsInvalid(callable $fn): bool
    {
        try {
            $fn();
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
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
