<?php

declare(strict_types=1);

namespace App\Tests;

use App\Agents\AtomizationPolicy;

/**
 * Deterministic coverage for the deferred atomization scheduling policy. Pure
 * function — no DB, no LLM. Verifies the context-scaled backlog threshold and
 * safety headroom (piecewise-linear interpolation over anchor points — NOT a
 * fixed percentage and NOT a universal constant), plus the trigger decision
 * across multiple context profiles (8k / 25k / 160k) and the EMA helper.
 */
class AtomizationPolicyTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runBacklogThresholdInterpolation();
        $this->runSafetyHeadroomInterpolation();
        $this->runShouldAtomizeAcrossProfiles();
        $this->runEmaStep();

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

    private function runBacklogThresholdInterpolation(): void
    {
        echo "\n=== backlog threshold: piecewise-linear over context size ===\n";
        $t = static fn(int $ctx) => AtomizationPolicy::backlogThresholdForContext($ctx);

        $this->testEq('8k ctx -> 1.5k', 1500, $t(8000));
        $this->testEq('16k ctx -> ~4.6k', 4559, $t(16000));
        $this->testEq('25k ctx -> 8k', 8000, $t(25000));
        $this->testEq('40k ctx -> ~10.4k', 10444, $t(40000));
        $this->testEq('64k ctx -> ~14.4k', 14356, $t(64000));
        $this->testEq('80k ctx -> ~17k', 16963, $t(80000));
        $this->testEq('128k ctx -> ~24.8k', 24785, $t(128000));
        $this->testEq('160k ctx -> 30k', 30000, $t(160000));

        // Clamping (flat) outside the anchor range.
        $this->testEq('below 8k clamps to 1.5k (ctx=0)', 1500, $t(0));
        $this->testEq('below 8k clamps to 1.5k (ctx=4000)', 1500, $t(4000));
        $this->testEq('above 160k clamps to 30k (ctx=200000)', 30000, $t(200000));
    }

    private function runSafetyHeadroomInterpolation(): void
    {
        echo "\n=== safety headroom: piecewise-linear over context size ===\n";
        $s = static fn(int $ctx) => AtomizationPolicy::safetyHeadroomForContext($ctx);

        $this->testEq('8k ctx -> 1k', 1000, $s(8000));
        $this->testEq('16k ctx -> ~1.7k', 1706, $s(16000));
        $this->testEq('25k ctx -> 2.5k', 2500, $s(25000));
        $this->testEq('160k ctx -> 8k', 8000, $s(160000));
        $this->testEq('below 8k clamps to 1k (ctx=0)', 1000, $s(0));
        $this->testEq('above 160k clamps to 8k (ctx=200000)', 8000, $s(200000));
    }

    private function runShouldAtomizeAcrossProfiles(): void
    {
        echo "\n=== trigger decision across context profiles ===\n";
        $d = static fn(int $backlog, int $headroom, int $ctx) =>
            AtomizationPolicy::shouldAtomizeBacklog($backlog, $headroom, $ctx);

        // 8k profile: threshold 1500, safety headroom 1000.
        $this->test('8k: backlog 1500 (== threshold) -> atomize', $d(1500, 3000, 8000));
        $this->test('8k: backlog 1000 + plenty headroom -> defer', !$d(1000, 3000, 8000));
        $this->test('8k: backlog 1000 + headroom 500 (< safety) -> atomize', $d(1000, 500, 8000));

        // 25k profile: threshold 8000, safety headroom 2500.
        $this->test('25k: backlog 8000 (== threshold) -> atomize', $d(8000, 20000, 25000));
        $this->test('25k: backlog 7000 + plenty headroom -> defer', !$d(7000, 20000, 25000));
        $this->test('25k: backlog 7000 + headroom 2000 (< safety) -> atomize', $d(7000, 2000, 25000));

        // 160k profile: threshold 30000, safety headroom 8000.
        $this->test('160k: backlog 30000 (== threshold) -> atomize', $d(30000, 150000, 160000));
        $this->test('160k: backlog 29000 + plenty headroom -> defer', !$d(29000, 150000, 160000));
        $this->test('160k: backlog 29000 + headroom 7000 (< safety) -> atomize', $d(29000, 7000, 160000));

        // A 6k backlog triggers at 8k but defers at 25k/160k (the same evidence,
        // different context pressure).
        $this->test('6k backlog: 8k triggers, 25k defers, 160k defers',
            $d(6000, 3000, 8000) && !$d(6000, 20000, 25000) && !$d(6000, 150000, 160000));

        // Empty/negative backlog never atomizes regardless of profile.
        $this->test('zero backlog -> false', !$d(0, 150000, 160000));
        $this->test('negative backlog -> false', !$d(-5, 150000, 160000));
    }

    private function runEmaStep(): void
    {
        echo "\n=== ema step ===\n";
        $this->testEq('ema(4500, 5000, 0.3) = 4650',
            4650.0, AtomizationPolicy::ema(4500.0, 5000.0, 0.3));
        $this->testEq('ema alpha=1 jumps to observed',
            5000.0, AtomizationPolicy::ema(4500.0, 5000.0, 1.0));
        $this->testEq('ema alpha=0 holds previous',
            4500.0, AtomizationPolicy::ema(4500.0, 5000.0, 0.0));
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
