<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\Agents\BriefingExtractor;

/**
 * BriefingExtractor — deterministic (mocked agent + pure chunking/dedup/validation).
 */
class BriefingExtractorTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== chunkByBudget ===\n";
        $this->testChunk();

        echo "\n=== isDuplicate ===\n";
        $this->testDuplicate();

        echo "\n=== dedupeAndValidate ===\n";
        $this->testDedupe();

        echo "\n=== extract (mocked agent) ===\n";
        $this->testExtract();

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

    private function testChunk(): void
    {
        $email = static function (int $id, int $bodyLen): array {
            return ['id' => $id, 'from' => 'A', 'subject' => 'S', 'body' => str_repeat('x', $bodyLen)];
        };

        // small bodies pack together
        $chunks = BriefingExtractor::chunkByBudget([$email(1, 100), $email(2, 100)], 1000);
        $this->testEq('small bodies pack into one chunk', 1, count($chunks));

        // 400-char bodies -> weight 650 each -> split at 1000
        $chunks = BriefingExtractor::chunkByBudget([$email(1, 400), $email(2, 400)], 1000);
        $this->testEq('oversized pair splits into two chunks', 2, count($chunks));

        // single oversized email gets its own chunk
        $chunks = BriefingExtractor::chunkByBudget([$email(1, 10000)], 1000);
        $this->testEq('single oversized email is one chunk', 1, count($chunks));
        $this->testEq('chunk contains that email', 1, count($chunks[0]));

        // degenerate (zero) budget -> one email per chunk, never unbounded
        $chunks = BriefingExtractor::chunkByBudget([$email(1, 100), $email(2, 100)], 0);
        $this->testEq('zero budget -> one email per chunk', 2, count($chunks));

        $this->testEq('empty input -> no chunks', [], BriefingExtractor::chunkByBudget([], 1000));
    }

    private function testDuplicate(): void
    {
        $this->test('exact normalized match is duplicate', BriefingExtractor::isDuplicate('Ebi 7 Reservation', [['content' => 'ebi 7 reservation']]));
        $this->test('unrelated content is not duplicate', !BriefingExtractor::isDuplicate('Call dentist', [['content' => 'buy milk']]));
        $this->test('empty task list is not duplicate', !BriefingExtractor::isDuplicate('Call dentist', []));
    }

    private function testDedupe(): void
    {
        $cards = [
            ['content' => 'Call dentist', 'due_string' => 'tomorrow', 'source_email_ref' => 1],
            ['content' => 'Call dentist', 'due_string' => 'tomorrow', 'source_email_ref' => 2],  // dup among cards
            ['content' => 'Ebi 7 Reservation', 'due_string' => 'Fri 2pm', 'source_email_ref' => 1],
            ['content' => 'Unknown ref', 'due_string' => 'soon', 'source_email_ref' => 99],       // invalid ref
            ['content' => '', 'due_string' => 'x', 'source_email_ref' => 1],                       // empty content
            ['content' => 'Renew passport', 'due_string' => 'next week', 'source_email_ref' => 1],
        ];
        $existing = [['content' => 'Ebi 7 reservation']];  // calendar dup

        $out = BriefingExtractor::dedupeAndValidate($cards, [1, 2], $existing);
        $contents = array_column($out, 'content');

        $this->testEq('drops duplicate, invalid ref, empty, calendar dup', ['Call dentist', 'Renew passport'], $contents);
        $this->testEq('source refs preserved', [1, 1], array_column($out, 'source_email_ref'));
    }

    private function testExtract(): void
    {
        $stub = new class extends AgentManager {
            public array $responses = [];
            public array $lastArgs = [];

            public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
            {
                $this->lastArgs = compact('purpose', 'reasoningEffort');
                return array_shift($this->responses) ?? '[]';
            }
        };

        $emails = [['id' => 1, 'from' => 'Alice', 'subject' => 'Dentist', 'body' => 'please call to confirm']];

        $stub->responses = ['[{"content":"Call dentist","due_string":"tomorrow 10am","source_email_ref":1}]'];
        $extractor = new BriefingExtractor($stub);
        $cards = $extractor->extract($emails, [], 10000);

        $this->testEq('extracts structured cards', [['content' => 'Call dentist', 'due_string' => 'tomorrow 10am', 'source_email_ref' => 1]], $cards);
        $this->testEq('extract purpose set', 'briefing_extract', $stub->lastArgs['purpose'] ?? null);
        $this->testEq('extract reasoning disabled', 'none', $stub->lastArgs['reasoningEffort'] ?? null);

        $this->testEq('empty emails -> no cards', [], $extractor->extract([], [], 10000));
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
