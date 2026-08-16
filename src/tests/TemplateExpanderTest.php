<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\TemplateExpander;

class TemplateExpanderTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runExpansion();
        $this->runLiterals();
        $this->runEmptyPlaceholder();

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

    private function runExpansion(): void
    {
        echo "\n=== expansion ===\n";

        $entry = [
            'url' => 'https://www.karriere.at/jobs?keywords={job_title}&locations={location}',
            'placeholders' => [
                'job_title' => ['php', 'go'],
                'location' => ['wien', 'remote'],
            ],
        ];
        $this->testEq('cartesian 2x2', [
            'https://www.karriere.at/jobs?keywords=php&locations=wien',
            'https://www.karriere.at/jobs?keywords=php&locations=remote',
            'https://www.karriere.at/jobs?keywords=go&locations=wien',
            'https://www.karriere.at/jobs?keywords=go&locations=remote',
        ], TemplateExpander::expand($entry));

        $multi = [
            'url' => 'https://www.karriere.at/jobs/{job_title}/wien',
            'placeholders' => ['job_title' => ['cloud engineer']],
        ];
        $this->testEq('space encoded as %20', [
            'https://www.karriere.at/jobs/cloud%20engineer/wien',
        ], TemplateExpander::expand($multi));

        $onlyTitle = [
            'url' => 'https://example.com/jobs?q={job_title}',
            'placeholders' => [
                'job_title' => ['php'],
                'location' => ['wien'],
            ],
        ];
        $this->testEq('unused placeholder ignored', [
            'https://example.com/jobs?q=php',
        ], TemplateExpander::expand($onlyTitle));
    }

    private function runLiterals(): void
    {
        echo "\n=== literal URLs ===\n";

        $this->testEq('no tokens -> literal', [
            'https://example.com/jobs?location=vienna',
        ], TemplateExpander::expand(['url' => 'https://example.com/jobs?location=vienna', 'placeholders' => []]));

        $this->testEq('empty url -> empty', [], TemplateExpander::expand(['url' => '', 'placeholders' => []]));
    }

    private function runEmptyPlaceholder(): void
    {
        echo "\n=== empty placeholder ===\n";

        $this->testEq('token present but no values -> empty', [], TemplateExpander::expand([
            'url' => 'https://example.com/jobs?q={job_title}',
            'placeholders' => ['job_title' => []],
        ]));
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
