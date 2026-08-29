<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\Agents\BriefingTriage;

/**
 * BriefingTriage — deterministic (mocked agent, pure normalization).
 */
class BriefingTriageTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== buildPrompt ===\n";
        $this->testPrompt();

        echo "\n=== normalizeSelection ===\n";
        $this->testNormalize();

        echo "\n=== select (mocked agent) ===\n";
        $this->testSelect();

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

    private function testPrompt(): void
    {
        $emails = [
            ['id' => 1, 'preview' => 'From: A | Subject: Lunch | Let us meet'],
            ['id' => 3, 'preview' => 'From: B | Subject: Newsletter | 50% off'],
        ];
        $prompt = BriefingTriage::buildPrompt($emails);
        $this->test('prompt lists ids + previews', strpos($prompt, '[1] From: A | Subject: Lunch') !== false && strpos($prompt, '[3] From: B') !== false);
        $this->test('prompt carries recall bias', stripos($prompt, 'When uncertain, INCLUDE') !== false);
        $this->test('prompt asks for JSON id array', strpos($prompt, 'JSON array of the integer ids') !== false);
    }

    private function testNormalize(): void
    {
        $this->testEq('flat int array', [1, 3, 5], BriefingTriage::normalizeSelection([1, 3, 5], [1, 2, 3, 4, 5]));
        $this->testEq('array of {id} objects', [1, 3], BriefingTriage::normalizeSelection([['id' => 1], ['id' => 3]], [1, 2, 3]));
        $this->testEq('wrapped {ids:[...]}', [1, 3], BriefingTriage::normalizeSelection(['ids' => [1, 3]], [1, 2, 3]));
        $this->testEq('string ids coerced', [1, 3], BriefingTriage::normalizeSelection(['1', '3'], [1, 2, 3]));
        $this->testEq('unknown ids filtered', [1], BriefingTriage::normalizeSelection([1, 99], [1, 2, 3]));
        $this->testEq('dedup + sorted', [1, 5], BriefingTriage::normalizeSelection([5, 1, 5], [1, 5]));

        // Safety net: empty result + candidates -> include all (never under-include on failure).
        $this->testEq('empty output falls back to all ids', [1, 2, 3], BriefingTriage::normalizeSelection([], [1, 2, 3]));
        $this->testEq('empty output + no candidates -> empty', [], BriefingTriage::normalizeSelection([], []));
    }

    private function testSelect(): void
    {
        $stub = new class extends AgentManager {
            public string $response = '[2, 4]';
            public array $lastArgs = [];

            public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
            {
                $this->lastArgs = compact('purpose', 'reasoningEffort');
                return $this->response;
            }
        };

        $emails = [
            ['id' => 1, 'preview' => 'a'],
            ['id' => 2, 'preview' => 'b'],
            ['id' => 3, 'preview' => 'c'],
            ['id' => 4, 'preview' => 'd'],
        ];

        $triage = new BriefingTriage($stub);
        $this->testEq('select returns parsed ids', [2, 4], $triage->select($emails));
        $this->testEq('triage purpose set', 'briefing_triage', $stub->lastArgs['purpose'] ?? null);
        $this->testEq('triage reasoning disabled', 'none', $stub->lastArgs['reasoningEffort'] ?? null);

        $this->testEq('empty emails -> empty selection', [], $triage->select([]));
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
