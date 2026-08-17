<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\PromptAssemblyService;

class ContextBudgetTest
{
    private \App\Database $db;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        $this->runEstimatePromptTokens();
        $this->runProjectsOverflow();

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

    // ===================================================================
    // estimatePromptTokens — category breakdown, eviction + rolling window
    // ===================================================================
    private function runEstimatePromptTokens(): void
    {
        echo "\n=== PromptAssemblyService::estimatePromptTokens ===\n";

        // Deterministic fake counter (no llama.cpp HTTP in tests).
        $prompt = new PromptAssemblyService($this->db, '/tmp', fn(string $t) => strlen($t));

        $history = [
            ['role' => 'system', 'message' => '12345678', 'message_type' => 'data_fetching', 'active_context' => 1],
            ['role' => 'system', 'message' => 'ABCDEFGHIJ', 'message_type' => 'data_fetching', 'active_context' => 0],
            ['role' => 'user', 'message' => 'ab', 'message_type' => 'text'],
        ];
        $b = $prompt->estimatePromptTokens('SYS', $history, 'XY');

        $this->testEq('system_prompt tokens', 3, $b['system_prompt']);
        $this->testEq('context_data excludes evicted rows', 8, $b['context_data']);
        $this->testEq('recent_chat tokens', 2, $b['recent_chat']);
        $this->testEq('current_turn tokens', 2, $b['current_turn']);
        $this->testEq('total = sum of four categories', 15, $b['total']);
        $this->test('no reasoning_budget key (sub-cap, not additive)', !array_key_exists('reasoning_budget', $b));

        $empty = $prompt->estimatePromptTokens('SYS', [], 'XY');
        $this->testEq('empty history -> context_data 0', 0, $empty['context_data']);
    }

    // ===================================================================
    // projectsOverflow — refusal threshold across profiles
    // ===================================================================
    private function runProjectsOverflow(): void
    {
        echo "\n=== PromptAssemblyService::projectsOverflow ===\n";

        $this->test('4096 profile refuses even empty prompt',
            PromptAssemblyService::projectsOverflow(['total' => 0], 4096, 4096, 256));

        $this->test('context-data overflow at 32k',
            PromptAssemblyService::projectsOverflow(['total' => 30000], 4096, 32768, 256));
        $this->test('eviction resolves overflow at 32k',
            !PromptAssemblyService::projectsOverflow(['total' => 25000], 4096, 32768, 256));

        $this->test('131k profile includes durable context',
            !PromptAssemblyService::projectsOverflow(['total' => 100000], 4096, 131072, 256));

        $this->test('no ctx limit -> never overflows',
            !PromptAssemblyService::projectsOverflow(['total' => 999999], 4096, 0, 256));
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
