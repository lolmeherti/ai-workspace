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

        $this->testEq('channel: content before, thought, content after',
            'beforeafter',
            ThoughtExtractor::strip('before<|channel|>thoughtthinking...<channel|>after'));

        $this->testEq('channel: thought only (no content)',
            '',
            ThoughtExtractor::strip('<|channel|>thoughtinternal reasoning<channel|>'));

        // Channel variant — missing leading pipe in close
        $this->testEq('channel: variant <|channel>thought with <channel|>',
            'result',
            ThoughtExtractor::strip('<|channel>thoughtthinking<channel|>result'));

        // Think format
        $this->testEq('think: thought at start, content after',
            'Hello world',
            ThoughtExtractor::strip('<think>I should greet the user</think>Hello world'));

        $this->testEq('think: content before, thought after',
            'Hello world',
            ThoughtExtractor::strip('Hello world<think>let me think</think>'));

        $this->testEq('think: thought only',
            '',
            ThoughtExtractor::strip('<think>internal reasoning</think>'));

        // No thought tags — unchanged
        $this->testEq('no tags: plain text unchanged',
            'Just a normal response.',
            ThoughtExtractor::strip('Just a normal response.'));

        $this->testEq('no tags: empty string unchanged',
            '',
            ThoughtExtractor::strip(''));

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
        $this->testEq('leading/trailing whitespace trimmed after strip',
            'content',
            ThoughtExtractor::strip('  <|channel|>thoughtx<channel|>content  '));

        // Content that contains partial tag-like strings (should not match)
        $this->testEq('partial tag in content: "channel" alone not stripped',
            'the word channel appears here',
            ThoughtExtractor::strip('the word channel appears here'));

        $this->testEq('partial tag in content: "think" alone not stripped',
            'I think this is fine',
            ThoughtExtractor::strip('I think this is fine'));

        // Multiline content with thought
        $this->testEq('multiline: thought plus multiline content',
            "line1\nline2",
            ThoughtExtractor::strip("<|channel|>thoughtx<channel|>line1\nline2"));
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
        $this->testEq('think: content extracted', 'Here is the answer', $r2['content']);

        // Content only — no thought
        $r3 = ThoughtExtractor::extract('Just a normal response.');
        $this->testEq('content only: thought empty', '', $r3['thought']);
        $this->testEq('content only: content is full text', 'Just a normal response.', $r3['content']);

        // Empty string
        $r4 = ThoughtExtractor::extract('');
        $this->testEq('empty string: thought empty', '', $r4['thought']);
        $this->testEq('empty string: content empty', '', $r4['content']);

        // Content before + thought + content after
        $r5 = ThoughtExtractor::extract('before<|channel|>thoughtthinking<channel|>after');
        $this->testEq('content before+after: thought extracted', 'thinking', $r5['thought']);
        $this->testEq('content before+after: content has both', 'beforeafter', $r5['content']);

        // Channel variant — missing leading pipe
        $r6 = ThoughtExtractor::extract('<|channel>thoughtreasoning<channel|>output');
        $this->testEq('channel variant: thought extracted', 'reasoning', $r6['thought']);
        $this->testEq('channel variant: content extracted', 'output', $r6['content']);

        // Thought only
        $r7 = ThoughtExtractor::extract('<|channel|>thoughtjust thinking<channel|>');
        $this->testEq('thought only: thought extracted', 'just thinking', $r7['thought']);
        $this->testEq('thought only: content empty', '', $r7['content']);

        // Multiline thought
        $r8 = ThoughtExtractor::extract("<|channel|>thoughtline1\nline2<channel|>result");
        $this->testEq('multiline thought: thought preserved', "line1\nline2", $r8['thought']);
        $this->testEq('multiline thought: content correct', 'result', $r8['content']);

        // Tool call with no thought — ensure extract doesn't eat part of the tool call
        $r9 = ThoughtExtractor::extract('get_todoist_tasks QUERY:flight');
        $this->testEq('tool call: thought empty', '', $r9['thought']);
        $this->testEq('tool call: content preserved exactly', 'get_todoist_tasks QUERY:flight', $r9['content']);

        // Newlines around thought block
        $r10 = ThoughtExtractor::extract("\n<|channel|>thoughtx<channel|>\nresult");
        $this->testEq('newlines around thought: thought extracted', 'x', $r10['thought']);
        $this->testEq('newlines around thought: content trimmed', 'result', $r10['content']);
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
        $this->test('containsOpenTag: channel variant (no pipe in close)',
            ThoughtExtractor::containsOpenTag('<|channel>thought'));
        $this->test('containsOpenTag: think format',
            ThoughtExtractor::containsOpenTag('<think>'));
        $this->test('containsOpenTag: partial "<|" only — false',
            !ThoughtExtractor::containsOpenTag('<|'));
        $this->test('containsOpenTag: "channel" without brackets — false',
            !ThoughtExtractor::containsOpenTag('channel'));
        $this->test('containsOpenTag: embedded in longer text',
            ThoughtExtractor::containsOpenTag('some text <|channel|>thought more text'));
        $this->test('containsOpenTag: empty string — false',
            !ThoughtExtractor::containsOpenTag(''));

        // containsCloseTag
        $this->test('containsCloseTag: channel format',
            ThoughtExtractor::containsCloseTag('<channel|>'));
        $this->test('containsCloseTag: channel variant (no pipe)',
            ThoughtExtractor::containsCloseTag('<channel>'));
        $this->test('containsCloseTag: think format',
            ThoughtExtractor::containsCloseTag('</think>'));
        $this->test('containsCloseTag: partial "channel" only — false',
            !ThoughtExtractor::containsCloseTag('channel'));
        $this->test('containsCloseTag: embedded in longer text',
            ThoughtExtractor::containsCloseTag('reasoning text <channel|> rest'));
        $this->test('containsCloseTag: empty string — false',
            !ThoughtExtractor::containsCloseTag(''));

        // openTagPosition
        $this->testEq('openTagPosition: at start of buffer', 0,
            ThoughtExtractor::openTagPosition('<|channel|>thoughtreasoning'));
        $this->testEq('openTagPosition: after 10 bytes of text', 10,
            ThoughtExtractor::openTagPosition('0123456789<|channel|>thought'));
        $this->testEq('openTagPosition: no tag — returns -1', -1,
            ThoughtExtractor::openTagPosition('no tag here'));
        $this->testEq('openTagPosition: think tag after text', 5,
            ThoughtExtractor::openTagPosition('hello<think>reasoning'));
        $this->testEq('openTagPosition: channel variant', 3,
            ThoughtExtractor::openTagPosition('foo<|channel>thought'));

        // MAX_OPEN_TAG_LEN constant
        $this->testEq('MAX_OPEN_TAG_LEN is 20', 20, ThoughtExtractor::MAX_OPEN_TAG_LEN);
        $this->testEq('MAX_CLOSE_TAG_LEN is 10', 10, ThoughtExtractor::MAX_CLOSE_TAG_LEN);
    }
}
