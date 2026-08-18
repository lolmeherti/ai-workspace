<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Tools\SearchMemoriesTool;

class SearchMemoriesToolTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runDeduplicateRows();

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

    private function runDeduplicateRows(): void
    {
        echo "\n=== deduplicateRows ===\n";

        // Multiple synonym sub-queries matching the same memory collapse to one row.
        $rows = [
            ['id' => 12, 'memory_text' => 'insurance number: 555'],
            ['id' => 12, 'memory_text' => 'insurance number: 555'],
            ['id' => 18, 'memory_text' => 'debit card number: 999'],
        ];
        $this->testEq(
            'same memory matched by several sub-queries appears once',
            [['id' => 12, 'memory_text' => 'insurance number: 555'], ['id' => 18, 'memory_text' => 'debit card number: 999']],
            SearchMemoriesTool::deduplicateRows($rows)
        );

        // Order of first appearance is preserved across sub-queries.
        $ordered = [
            ['id' => 5, 'memory_text' => 'a'],
            ['id' => 7, 'memory_text' => 'b'],
            ['id' => 5, 'memory_text' => 'a'],
        ];
        $this->testEq(
            'first-seen order preserved',
            [['id' => 5, 'memory_text' => 'a'], ['id' => 7, 'memory_text' => 'b']],
            SearchMemoriesTool::deduplicateRows($ordered)
        );

        // No duplication in a single query — result unchanged.
        $single = [['id' => 3, 'memory_text' => 'x'], ['id' => 4, 'memory_text' => 'y']];
        $this->testEq('single query with distinct rows unchanged', $single, SearchMemoriesTool::deduplicateRows($single));

        // Empty input.
        $this->testEq('empty input -> []', [], SearchMemoriesTool::deduplicateRows([]));

        // Rows with missing/zero ids are dropped defensively.
        $this->testEq(
            'missing/zero id rows skipped',
            [['id' => 1, 'memory_text' => 'ok']],
            SearchMemoriesTool::deduplicateRows([
                ['id' => 1, 'memory_text' => 'ok'],
                ['id' => 0, 'memory_text' => 'bad'],
                ['memory_text' => 'no id'],
            ])
        );
    }
}
