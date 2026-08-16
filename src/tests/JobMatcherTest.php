<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\JobMatcher;

class JobMatcherTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runNormalizeCompany();
        $this->runNormalizeDomain();
        $this->runCompanyBlocking();
        $this->runDomainBlocking();
        $this->runDedupe();
        $this->runStaleness();

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

    private function runNormalizeCompany(): void
    {
        echo "\n=== normalizeCompany ===\n";

        $this->testEq('strips GmbH', 'bitpanda', JobMatcher::normalizeCompany('Bitpanda GmbH'));
        $this->testEq('strips AG', 'siemens', JobMatcher::normalizeCompany('Siemens AG'));
        $this->testEq('strips Ltd', 'acme', JobMatcher::normalizeCompany('Acme Ltd'));
        $this->testEq('strips Inc', 'acme', JobMatcher::normalizeCompany('Acme, Inc.'));
        $this->testEq('strips LLC', 'acme', JobMatcher::normalizeCompany('Acme LLC'));
        $this->testEq('strips compound suffixes', 'bitpanda', JobMatcher::normalizeCompany('Bitpanda GmbH & Co KG'));
        $this->testEq('lowercases', 'acme', JobMatcher::normalizeCompany('ACME'));
        $this->testEq('preserves umlauts', 'müller', JobMatcher::normalizeCompany('Müller GmbH'));
        $this->testEq('no suffix left alone', 'red bull', JobMatcher::normalizeCompany('Red Bull'));
    }

    private function runNormalizeDomain(): void
    {
        echo "\n=== normalizeDomain ===\n";

        $this->testEq('strips www and trailing slash', 'devjobs.at', JobMatcher::normalizeDomain('https://www.devjobs.at/'));
        $this->testEq('lowercases', 'devjobs.at', JobMatcher::normalizeDomain('WWW.DEVJOBS.AT'));
        $this->testEq('keeps subdomain', 'jobs.example.com', JobMatcher::normalizeDomain('jobs.example.com'));
    }

    private function runCompanyBlocking(): void
    {
        echo "\n=== company blocking ===\n";

        $this->test('exact match blocked', JobMatcher::isCompanyBlocked(['Bitpanda'], 'Bitpanda GmbH'));
        $this->test('suffix-insensitive', JobMatcher::isCompanyBlocked(['Bitpanda GmbH'], 'Bitpanda'));
        $this->test('containment blocked', JobMatcher::isCompanyBlocked(['bull'], 'Red Bull GmbH'));
        $this->test('non-match not blocked', !JobMatcher::isCompanyBlocked(['Bitpanda'], 'Siemens AG'));
        $this->test('empty blocked list not blocked', !JobMatcher::isCompanyBlocked([], 'Bitpanda'));
    }

    private function runDomainBlocking(): void
    {
        echo "\n=== domain blocking ===\n";

        $this->test('exact match blocked', JobMatcher::isDomainBlocked(['devjobs.at'], 'devjobs.at'));
        $this->test('www-insensitive', JobMatcher::isDomainBlocked(['devjobs.at'], 'www.devjobs.at'));
        $this->test('non-match not blocked', !JobMatcher::isDomainBlocked(['devjobs.at'], 'karriere.at'));
        $this->test('empty blocked list not blocked', !JobMatcher::isDomainBlocked([], 'devjobs.at'));
    }

    private function runDedupe(): void
    {
        echo "\n=== dedupe ===\n";

        $existing = [['url' => 'https://devjobs.at/job/1', 'posted_at' => '2026-08-01 10:00:00']];

        $this->test('same url + timestamp is duplicate', JobMatcher::isDuplicate('https://devjobs.at/job/1', '2026-08-01 10:00:00', $existing));
        $this->test('different timestamp is not duplicate', !JobMatcher::isDuplicate('https://devjobs.at/job/1', '2026-08-02 10:00:00', $existing));
        $this->test('different url is not duplicate', !JobMatcher::isDuplicate('https://devjobs.at/job/2', '2026-08-01 10:00:00', $existing));
        $this->test('empty existing list is not duplicate', !JobMatcher::isDuplicate('https://devjobs.at/job/1', '2026-08-01 10:00:00', []));
    }

    private function runStaleness(): void
    {
        echo "\n=== staleness ===\n";

        $recent = date('Y-m-d H:i:s', strtotime('-13 days'));
        $old = date('Y-m-d H:i:s', strtotime('-15 days'));

        $this->test('recent job not stale', !JobMatcher::isStale($recent));
        $this->test('old job stale', JobMatcher::isStale($old));
        $this->test('invalid date treated stale', JobMatcher::isStale('not-a-date'));
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
