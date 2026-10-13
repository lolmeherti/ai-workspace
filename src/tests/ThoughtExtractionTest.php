<?php

declare(strict_types=1);

namespace App\Tests;

use App\ThoughtExtractor;

class ThoughtExtractionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runStrip();
        $this->runExtract();
        $this->runOpenTagDetection();

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
            $this->failures[] = ['label' => $label, 'condition' => false];
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

    // ===================================================================
    // strip() — removes thought blocks, returns clean content
    // ===================================================================
    private function runStrip(): void
    {
        echo "\n=== strip() ===\n";

        // Channel format — complete block
        $this->testEq('channel: thought at start, content after',
            'Hello world',
            ThoughtExtractor::strip('<|channel|>thoughtI should greet the user<channel|>Hello world'));

        $this->testEq('channel: content before, thought after',
            'Hello world',
            ThoughtExtractor::strip('Hello world<|channel|>thoughtlet me think<channel|>'));


        $this->testEq('channel: thought only (no content)',
            '',
            ThoughtExtractor::strip('<|channel|>thoughtinternal reasoning<channel|>'));

        // Channel variant — missing leading pipe in close

        // Think format
        $this->testEq('think: thought at start, content after',
            'Hello world',
            ThoughtExtractor::strip('<think>I should greet the user</think>Hello world'));



        // No thought tags — unchanged
        $this->testEq('no tags: plain text unchanged',
            'Just a normal response.',
            ThoughtExtractor::strip('Just a normal response.'));


        $this->testEq('no tags: tool call format preserved',
            'search_files QUERY:cv',
            ThoughtExtractor::strip('search_files QUERY:cv'));

        // Multiple thought blocks
        $this->testEq('multiple channel thoughts stripped',
            'result',
            ThoughtExtractor::strip('<|channel|>thoughtfirst<channel|><|channel|>thoughtsecond<channel|>result'));

        $this->testEq('mixed channel + think tags stripped',
            'done',
            ThoughtExtractor::strip('<|channel|>thoughtone<channel|><think>two</think>done'));

        // Malformed — no close tag (non-greedy regex won't match without close)
        $this->test('malformed: no close tag — open tag remains (limitation)',
            str_contains(ThoughtExtractor::strip('<|channel|>thoughtno close tag'), '<|channel|>'));

        // Whitespace trimming

        // Content that contains partial tag-like strings (should not match)


        // Multiline content with thought
    }

    // ===================================================================
    // extract() — separates thought and content
    // ===================================================================
    private function runExtract(): void
    {
        echo "\n=== extract() ===\n";

        // Channel format
        $r1 = ThoughtExtractor::extract('<|channel|>thoughtI should search<channel|>search_files QUERY:cv');
        $this->testEq('channel: thought extracted', 'I should search', $r1['thought']);
        $this->testEq('channel: content extracted', 'search_files QUERY:cv', $r1['content']);

        // Think format
        $r2 = ThoughtExtractor::extract('<think>let me check</think>Here is the answer');
        $this->testEq('think: thought extracted', 'let me check', $r2['thought']);

        // Content only — no thought
        $r3 = ThoughtExtractor::extract('Just a normal response.');
        $this->testEq('content only: thought empty', '', $r3['thought']);

        // Empty string
        $r4 = ThoughtExtractor::extract('');

        // Content before + thought + content after
        $r5 = ThoughtExtractor::extract('before<|channel|>thoughtthinking<channel|>after');

        // Channel variant — missing leading pipe
        $r6 = ThoughtExtractor::extract('<|channel>thoughtreasoning<channel|>output');

        // Thought only
        $r7 = ThoughtExtractor::extract('<|channel|>thoughtjust thinking<channel|>');

        // Multiline thought
        $r8 = ThoughtExtractor::extract("<|channel|>thoughtline1\nline2<channel|>result");

        // Tool call with no thought — ensure extract doesn't eat part of the tool call
        $r9 = ThoughtExtractor::extract('get_calendar_tasks QUERY:flight');
        $this->testEq('tool call: thought empty', '', $r9['thought']);
        $this->testEq('tool call: content preserved exactly', 'get_calendar_tasks QUERY:flight', $r9['content']);

        // Newlines around thought block
        $r10 = ThoughtExtractor::extract("\n<|channel|>thoughtx<channel|>\nresult");

        // Multiple thought blocks in one message (tool turns reason again after results)
        $r11 = ThoughtExtractor::extract('<|channel|>thoughtfirst reasoning<channel|>partial answer<|channel|>thoughtsecond reasoning<channel|>final answer');
        $this->testEq('multi-block: both thoughts returned', "first reasoning\n\nsecond reasoning", $r11['thought']);

        // Mixed formats, multiple blocks
        $r12 = ThoughtExtractor::extract('<think>a</think>x<|channel|>thoughtb<channel|>y<think>c</think>z');

        // Empty thought blocks are ignored, not merged as blank lines
        $r13 = ThoughtExtractor::extract('<|channel|>thought<channel|>answer');

        // Unterminated thought block: reasoning is captured, but strip() only removes
        // closed blocks, so the raw text survives as content (unchanged behaviour).
        $r14 = ThoughtExtractor::extract('<|channel|>thoughtunterminated');
        $this->testEq('unterminated thought: thought captured', 'unterminated', $r14['thought']);
    }

    // ===================================================================
    // Open/close tag detection — used by streaming buffer
    // ===================================================================
    private function runOpenTagDetection(): void
    {
        echo "\n=== containsOpenTag / containsCloseTag / openTagPosition ===\n";

        // containsOpenTag
        $this->test('containsOpenTag: channel format',
            ThoughtExtractor::containsOpenTag('<|channel|>thought'));
        $this->test('containsOpenTag: empty string — false',
            !ThoughtExtractor::containsOpenTag(''));

        // containsCloseTag

        // openTagPosition
        $this->testEq('openTagPosition: no tag — returns -1', -1,
            ThoughtExtractor::openTagPosition('no tag here'));

        // MAX_OPEN_TAG_LEN constant
    }
}
