<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\ModelPerformanceReport;

class ModelPerformanceReportTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runPickAnswerCall();
        $this->runBuild();

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

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testClose(string $label, float $expected, mixed $actual): void
    {
        $ok = is_numeric($actual) && abs($expected - (float)$actual) < 1e-6;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function runPickAnswerCall(): void
    {
        echo "\n=== pickAnswerCall() ===\n";
        $report = new ModelPerformanceReport();

        $toolDecision = ['purpose' => 'firstpass', 'content_tok' => 0, 'pred_tps' => 100.0];
        $answer = ['purpose' => 'answer', 'content_tok' => 40, 'pred_tps' => 60.0];
        $normalFirstpass = ['purpose' => 'firstpass', 'content_tok' => 50, 'pred_tps' => 40.0];

        $this->testEq('prefers answer over tool-decision firstpass',
            $answer,
            $report->pickAnswerCall([$toolDecision, $answer]));

        $this->testEq('skips content-less firstpass (tool decision), falls back to content firstpass',
            $normalFirstpass,
            $report->pickAnswerCall([$toolDecision, $normalFirstpass]));

        $this->testEq('no content anywhere -> null',
            null,
            $report->pickAnswerCall([$toolDecision]));

        $condenser = ['purpose' => 'condenser', 'content_tok' => 5, 'pred_tps' => 10.0];
        $this->testEq('defensive fallback to any content-carrying call',
            $condenser,
            $report->pickAnswerCall([$condenser]));
    }

    private function runBuild(): void
    {
        echo "\n=== build() ===\n";

        $history = [
            // model A — tool turn: tool decision (content 0) + answer; then a normal turn.
            ['model' => 'A', 'perf_metrics' => json_encode(['calls' => [
                ['purpose' => 'firstpass', 'content_tok' => 0, 'pred_tps' => 200.0, 'prompt_tps' => 5000.0, 'cache_n' => 0, 'prompt_tokens' => 1000],
                ['purpose' => 'answer', 'content_tok' => 40, 'pred_tps' => 60.0, 'prompt_tps' => 1100.0, 'cache_n' => 500, 'prompt_tokens' => 1000],
            ]]), 'rating' => 1, 'rating_reason' => null],
            ['model' => 'A', 'perf_metrics' => json_encode(['calls' => [
                ['purpose' => 'firstpass', 'content_tok' => 50, 'pred_tps' => 40.0, 'prompt_tps' => 900.0, 'cache_n' => 200, 'prompt_tokens' => 800],
            ]]), 'rating' => 0, 'rating_reason' => 'wrong'],
            // model B — one turn, prefill/cache missing (0).
            ['model' => 'B', 'perf_metrics' => json_encode(['calls' => [
                ['purpose' => 'answer', 'content_tok' => 30, 'pred_tps' => 30.0, 'prompt_tps' => 0.0, 'cache_n' => 0, 'prompt_tokens' => 0],
            ]]), 'rating' => 0, 'rating_reason' => 'bad_query'],
        ];

        $events = [
            ['model' => 'A', 'event_type' => 'llm_tool_parse_error'],
            ['model' => 'A', 'event_type' => 'llm_tool_parse_error'],
            ['model' => 'A', 'event_type' => 'llm_connection_error'],
            ['model' => 'B', 'event_type' => 'llm_tool_no_match'],
            ['model' => 'C', 'event_type' => 'llm_connection_error'],
        ];

        $report = (new ModelPerformanceReport())->build($history, $events);

        $this->testEq('3 distinct models (A, B, C incl. events-only)', 3, count($report));

        $byModel = [];
        foreach ($report as $r) {
            $byModel[$r['model']] = $r;
        }

        // Sort order: turns desc -> A(2), B(1), C(0).
        $this->testEq('sort by turns desc: first is A', 'A', $report[0]['model']);
        $this->testEq('sort by turns desc: last is C', 'C', $report[2]['model']);

        $a = $byModel['A'];
        $this->testEq('A turns = 2', 2, $a['turns']);
        $this->testClose('A avg_decode_tps = 50', 50.0, $a['avg_decode_tps']);
        $this->testClose('A avg_prefill_tps = 1000', 1000.0, $a['avg_prefill_tps']);
        $this->testClose('A avg_cache_pct = 37.5', 37.5, $a['avg_cache_pct']);
        $this->testEq('A model_faults = 2', 2, $a['model_faults']);
        $this->testEq('A fault_breakdown llm_tool_parse_error = 2', 2, $a['fault_breakdown']['llm_tool_parse_error'] ?? null);
        $this->testEq('A other_errors = 1 (connection)', 1, $a['other_errors']);
        $this->testEq('A up = 1', 1, $a['up']);
        $this->testEq('A down = 1', 1, $a['down']);
        $this->testEq('A down_reasons wrong = 1', 1, $a['down_reasons']['wrong'] ?? null);
        $this->testClose('A pct_positive = 50', 50.0, $a['pct_positive']);

        $b = $byModel['B'];
        $this->testEq('B turns = 1', 1, $b['turns']);
        $this->testClose('B avg_decode_tps = 30', 30.0, $b['avg_decode_tps']);
        $this->testEq('B avg_prefill_tps null (prompt_tps=0)', null, $b['avg_prefill_tps']);
        $this->testEq('B avg_cache_pct null (prompt_tokens=0)', null, $b['avg_cache_pct']);
        $this->testEq('B model_faults = 1 (no_match)', 1, $b['model_faults']);
        $this->testEq('B other_errors = 0', 0, $b['other_errors']);
        $this->testEq('B down_reasons bad_query = 1', 1, $b['down_reasons']['bad_query'] ?? null);
        $this->testClose('B pct_positive = 0', 0.0, $b['pct_positive']);

        $c = $byModel['C'];
        $this->testEq('C turns = 0 (events only)', 0, $c['turns']);
        $this->testEq('C model_faults = 0', 0, $c['model_faults']);
        $this->testEq('C other_errors = 1', 1, $c['other_errors']);
        $this->testEq('C pct_positive null (no ratings)', null, $c['pct_positive']);
    }
}
