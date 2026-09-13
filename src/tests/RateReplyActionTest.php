<?php

declare(strict_types=1);

namespace App\Tests;

use App\Actions\RateReplyAction;

class RateReplyActionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runResolveRating();
        $this->runTaxonomy();

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

    private function runResolveRating(): void
    {
        echo "\n=== resolveRating() ===\n";

        // Upvote (no reason).
        $r = RateReplyAction::resolveRating(1, null, null);
        $this->testEq('upvote: rating=1, reason=null, no error', [1, null, null], [$r['rating'], $r['reason'], $r['error']]);

        // Downvote with valid reason.
        $r = RateReplyAction::resolveRating(0, 'wrong', null);
        $this->testEq('downvote: rating=0, reason kept', [0, 'wrong', null], [$r['rating'], $r['reason'], $r['error']]);

        // Downvote missing reason.
        $r = RateReplyAction::resolveRating(0, null, null);
        $this->testEq('downvote without reason -> error', 'A reason is required for a downvote.', $r['error']);

        // Downvote unknown reason.
        $r = RateReplyAction::resolveRating(0, 'bogus', null);
        $this->testEq('downvote unknown reason -> error', 'A reason is required for a downvote.', $r['error']);

        // Invalid rating value.
        $r = RateReplyAction::resolveRating(2, null, null);
        $this->testEq('rating=2 -> error', 'Invalid rating.', $r['error']);

        // Upvote drops any reason.
        $r = RateReplyAction::resolveRating(1, 'wrong', null);
        $this->testEq('upvote drops reason', [1, null, null], [$r['rating'], $r['reason'], $r['error']]);

        // Toggle up: rating 1 again clears.
        $r = RateReplyAction::resolveRating(1, null, 1);
        $this->testEq('upvote again -> clears', [null, null, null], [$r['rating'], $r['reason'], $r['error']]);

        // Toggle down: rating 0 again clears (and reason).
        $r = RateReplyAction::resolveRating(0, 'wrong', 0);
        $this->testEq('downvote again -> clears', [null, null, null], [$r['rating'], $r['reason'], $r['error']]);

        // Explicit clear (null rating).
        $r = RateReplyAction::resolveRating(null, null, 1);
        $this->testEq('explicit clear -> rating null', [null, null, null], [$r['rating'], $r['reason'], $r['error']]);

        // Switching from down to up (different value, no toggle).
        $r = RateReplyAction::resolveRating(1, null, 0);
        $this->testEq('switch down->up', [1, null, null], [$r['rating'], $r['reason'], $r['error']]);
    }

    private function runTaxonomy(): void
    {
        echo "\n=== reason taxonomy ===\n";

        $this->test('bad_query is a known reason', array_key_exists('bad_query', RateReplyAction::DOWNVOTE_REASONS));
        $this->test('bad_query is tool-turn only', in_array('bad_query', RateReplyAction::TOOL_TURN_REASONS, true));
        $this->test('wrong_tool is tool-turn only', in_array('wrong_tool', RateReplyAction::TOOL_TURN_REASONS, true));
        $this->test('bad_tool_args is tool-turn only', in_array('bad_tool_args', RateReplyAction::TOOL_TURN_REASONS, true));
        $this->test('wrong is NOT tool-turn only', !in_array('wrong', RateReplyAction::TOOL_TURN_REASONS, true));
        $this->test('other is a known reason', array_key_exists('other', RateReplyAction::DOWNVOTE_REASONS));
    }
}
