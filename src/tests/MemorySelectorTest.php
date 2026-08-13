<?php

declare(strict_types=1);

namespace App\Tests;

use App\Agents\MemorySelector;

class MemorySelectorTest
{
    private MemorySelector $selector;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->selector = new MemorySelector($db);
    }

    public function run(): bool
    {
        $this->runBuildBoolQuery();

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

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    private function runBuildBoolQuery(): void
    {
        echo "\n=== buildBoolQuery ===\n";

        // Partial-overlap regression: the previous query prefixed every word with '+',
        // turning an expanded query (car, auto, fix, repair, mechanic) into an AND that
        // required all terms. A memory containing only one term was therefore missed.
        $query = $this->invokePrivate($this->selector, 'buildBoolQuery', ['car', 'auto', 'fix', 'repair', 'mechanic']);

        $this->testEq('multi-word query joins terms with spaces', 'car auto fix repair mechanic', $query);
        $this->test('no AND (+) operator — a memory matching only one term is retrievable', !str_contains($query, '+'));
        $this->test('no leading + on the first term', !str_starts_with($query, '+'));

        $this->testEq('single-word query unchanged', 'car', $this->invokePrivate($this->selector, 'buildBoolQuery', ['car']));
        $this->testEq('two-word query', 'car repair', $this->invokePrivate($this->selector, 'buildBoolQuery', ['car', 'repair']));
        $this->testEq('empty word list returns empty string', '', $this->invokePrivate($this->selector, 'buildBoolQuery', []));
    }
}
