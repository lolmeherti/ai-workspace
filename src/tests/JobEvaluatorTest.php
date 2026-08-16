<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\JobEvaluator;

class JobEvaluatorTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== verdict parsing ===\n";

        $keep = JobEvaluator::parseVerdict('{"decision": "KEEP", "comment": "strong match"}');
        $this->testEq('KEEP parsed', 'KEEP', $keep['decision']);
        $this->testEq('comment preserved', 'strong match', $keep['comment']);

        $discard = JobEvaluator::parseVerdict('{"decision": "discard", "comment": "wrong stack"}');
        $this->testEq('lowercase discard uppercased', 'DISCARD', $discard['decision']);

        $noComment = JobEvaluator::parseVerdict('{"decision": "KEEP"}');
        $this->testEq('missing comment defaults empty', '', $noComment['comment']);

        $invalid = JobEvaluator::parseVerdict('{"decision": "MAYBE", "comment": "unsure"}');
        $this->testEq('unknown decision falls back to DISCARD', 'DISCARD', $invalid['decision']);

        $empty = JobEvaluator::parseVerdict('{}');
        $this->testEq('empty object falls back to DISCARD', 'DISCARD', $empty['decision']);

        $garbage = JobEvaluator::parseVerdict('this is not json at all');
        $this->testEq('non-JSON falls back to DISCARD', 'DISCARD', $garbage['decision']);
        $this->testEq('non-JSON comment empty', '', $garbage['comment']);

        $prose = JobEvaluator::parseVerdict('Here is the result: {"decision": "KEEP", "comment": "good"} end');
        $this->testEq('prose-wrapped JSON still parsed', 'KEEP', $prose['decision']);

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
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }
}
