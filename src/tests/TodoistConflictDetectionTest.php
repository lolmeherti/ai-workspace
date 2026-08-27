<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Tools\TodoistApiClient;

/**
 * Todoist conflict detection — deterministic.
 *
 * detectConflict() is the safety-critical guard that prevents duplicate or
 * overlapping Todoist tasks from being created silently. Pure logic, no
 * network, no LLM.
 */
class TodoistConflictDetectionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== detectConflict ===\n";

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Buy Milk']],
            'buy milk',
            '2026-09-29 10:00'
        );
        $this->test('exact-duplicate content (case-insensitive) is a conflict', $r !== null && ($r['is_duplicate'] ?? false) === true);

        $r = TodoistApiClient::detectConflict(
            [['content' => '  Buy Milk  ']],
            'buy milk',
            null
        );
        $this->test('duplicate with surrounding whitespace still matches', $r !== null && ($r['is_duplicate'] ?? false) === true);

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Other', 'due' => ['datetime' => '2026-09-29 10:15']]],
            'New task',
            '2026-09-29 10:00'
        );
        $this->test('due within 30 min is a conflict (not a duplicate)', $r !== null && ($r['is_duplicate'] ?? false) === false);

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Other', 'due' => ['datetime' => '2026-09-29 11:00']]],
            'New task',
            '2026-09-29 10:00'
        );
        $this->test('due 60 min out is NOT a conflict', $r === null);

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Other', 'due' => ['datetime' => '2026-09-29 10:30']]],
            'New task',
            '2026-09-29 10:00'
        );
        $this->test('exactly 30 min boundary is NOT a conflict', $r === null);

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Other']],
            'New task',
            '2026-09-29 10:00'
        );
        $this->test('existing task with no due date is not a time conflict', $r === null);

        $r = TodoistApiClient::detectConflict(
            [['content' => 'Other']],
            'New task',
            ''
        );
        $this->test('empty proposed due only checks duplicates', $r === null);

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
}
