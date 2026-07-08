<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Tools\SearchWebTool;

class MultiQueryTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runSplitQueries();
        $this->runCombineResults();

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

    // ==================================================================
    // splitQueries
    // ==================================================================
    private function runSplitQueries(): void
    {
        echo "\n=== splitQueries ===\n";

        // Tests on the canonical implementation (SearchWebTool)
        $map = [
            [SearchWebTool::class, 'WebTool'],
        ];
        foreach ($map as [$class, $prefix]) {

            $this->testEq(
                "{$prefix}: single query no comma",
                ['iPhone 15 specs'],
                $this->invokeSplit($class, 'iPhone 15 specs')
            );

            $this->testEq(
                "{$prefix}: two comma-separated queries",
                ['iPhone 15 specs', 'Samsung Galaxy S24 specs'],
                $this->invokeSplit($class, 'iPhone 15 specs, Samsung Galaxy S24 specs')
            );

            $this->testEq(
                "{$prefix}: three queries",
                ['alpha', 'beta', 'gamma'],
                $this->invokeSplit($class, 'alpha, beta, gamma')
            );

            $this->testEq(
                "{$prefix}: trims whitespace around queries",
                ['alpha', 'beta'],
                $this->invokeSplit($class, '  alpha ,  beta  ')
            );

            $this->testEq(
                "{$prefix}: trailing comma produces no empty element",
                ['only'],
                $this->invokeSplit($class, 'only,')
            );

            $this->testEq(
                "{$prefix}: leading comma produces no empty element",
                ['only'],
                $this->invokeSplit($class, ',only')
            );

            $this->testEq(
                "{$prefix}: double comma produces no empty element",
                ['a', 'b'],
                $this->invokeSplit($class, 'a,,b')
            );

            $this->testEq(
                "{$prefix}: empty string returns empty array",
                [],
                $this->invokeSplit($class, '')
            );

            $this->testEq(
                "{$prefix}: only commas returns empty array",
                [],
                $this->invokeSplit($class, ',,,')
            );

            $this->testEq(
                "{$prefix}: query with internal comma (city, state) stays as one",
                ['best pizza in Austin Texas'],
                $this->invokeSplit($class, 'best pizza in Austin Texas')
            );
        }
    }

    // ==================================================================
    // combineResults
    // ==================================================================
    private function runCombineResults(): void
    {
        echo "\n=== combineResults ===\n";

        // SearchWebTool prefix
        $this->testEq(
            'Web: two results combine with [Search A] [Search B]',
            "[Search A: \"q1\"]\nr1\n\n[Search B: \"q2\"]\nr2",
            $this->invokeCombine(SearchWebTool::class, ['q1', 'q2'], ['r1', 'r2'], 'Search')
        );

        $this->testEq(
            'Web: skips empty result (B has content, A empty)',
            "[Search B: \"q2\"]\nr2",
            $this->invokeCombine(SearchWebTool::class, ['q1', 'q2'], ['', 'r2'], 'Search')
        );

        $this->testEq(
            'Web: skips missing result index',
            "[Search A: \"q1\"]\nr1",
            $this->invokeCombine(SearchWebTool::class, ['q1', 'q2'], ['r1'], 'Search')
        );

        $this->testEq(
            'Web: three results become A B C',
            "[Search A: \"a\"]\nra\n\n[Search B: \"b\"]\nrb\n\n[Search C: \"c\"]\nrc",
            $this->invokeCombine(SearchWebTool::class, ['a', 'b', 'c'], ['ra', 'rb', 'rc'], 'Search')
        );

        $this->testEq(
            'Web: all empty results returns empty string',
            '',
            $this->invokeCombine(SearchWebTool::class, ['q1', 'q2'], ['', ''], 'Search')
        );

        // Memory prefix (same implementation, different prefix)
        $this->testEq(
            'Memory: two results combine with [Memory A] [Memory B]',
            "[Memory A: \"ib\"]\nIBAN result\n\n[Memory B: \"ad\"]\naddress result",
            $this->invokeCombine(SearchWebTool::class, ['ib', 'ad'], ['IBAN result', 'address result'], 'Memory')
        );

        $this->testEq(
            'Memory: skips empty sections',
            "[Memory B: \"ad\"]\naddress result",
            $this->invokeCombine(SearchWebTool::class, ['ib', 'ad'], ['', 'address result'], 'Memory')
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================
    private function invokeSplit(string $class, string $raw): array
    {
        $ref = new \ReflectionMethod($class, 'splitQueries');
        $ref->setAccessible(true);
        return $ref->invoke(null, $raw);
    }

    private function invokeCombine(string $class, array $queries, array $results, string $prefix): string
    {
        $ref = new \ReflectionMethod($class, 'combineResults');
        $ref->setAccessible(true);
        return $ref->invoke(null, $queries, $results, $prefix);
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  PASS: {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = [
                'label' => $label,
                'expected' => $expected,
                'actual' => $actual,
            ];
            echo "  FAIL: {$label}\n";
            echo "    expected: " . json_encode($expected) . "\n";
            echo "    actual:   " . json_encode($actual) . "\n";
        }
    }
}
