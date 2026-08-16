<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jobs\Adapters\GenericListing;

class JobAdapterTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runResolveUrl();
        $this->runExtractJobLinks();
        $this->runNormalizeCandidateKey();
        $this->runFilterLinks();

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

    private function runResolveUrl(): void
    {
        echo "\n=== url resolution ===\n";

        $this->testEq('absolute http preserved', 'http://a.at/jobs/1', GenericListing::resolveUrl('https://a.at/', 'http://a.at/jobs/1'));
        $this->testEq('absolute https preserved', 'https://a.at/jobs/1', GenericListing::resolveUrl('https://a.at/', 'https://a.at/jobs/1'));
        $this->testEq('protocol-relative resolved', 'https://cdn.at/x', GenericListing::resolveUrl('https://a.at/careers/', '//cdn.at/x'));
        $this->testEq('root-relative resolved', 'https://a.at/jobs/1', GenericListing::resolveUrl('https://a.at/careers/', '/jobs/1'));
        $this->testEq('relative resolved against dir', 'https://a.at/careers/jobs/1', GenericListing::resolveUrl('https://a.at/careers/', 'jobs/1'));
        $this->testEq('relative without trailing slash', 'https://a.at/jobs/1', GenericListing::resolveUrl('https://a.at/careers', 'jobs/1'));
        $this->testEq('query-only href keeps path', 'https://a.at/jobs/x?page=2', GenericListing::resolveUrl('https://a.at/jobs/x', '?page=2'));
        $this->testEq('fragment stripped', 'https://a.at/jobs/1', GenericListing::resolveUrl('https://a.at/', 'https://a.at/jobs/1#apply'));
        $this->testEq('port preserved', 'https://a.at:8443/jobs/1', GenericListing::resolveUrl('https://a.at:8443/', '/jobs/1'));
        $this->testEq('non-http scheme rejected', null, GenericListing::resolveUrl('https://a.at/', 'ftp://a.at/x'));
        $this->testEq('base without host returns null', null, GenericListing::resolveUrl('not-a-url', '/jobs/1'));
    }

    private function runExtractJobLinks(): void
    {
        echo "\n=== job link extraction ===\n";

        $html = '<html><body>'
            . '<a href="/jobs/1">Senior Developer</a>'
            . '<a href="/about">About Us</a>'
            . '<a href="#top">Top</a>'
            . '<a href="javascript:void(0)">Click</a>'
            . '<a href="mailto:x@y.com">Email</a>'
            . '<a href="/jobs/1">Duplicate</a>'
            . '<a href="/roles/lead-engineer-position">Lead Engineer at a growing fintech startup</a>'
            . '<a href="/blog/post">Read</a>'
            . '</body></html>';

        $links = GenericListing::extractJobLinks($html, 'https://example.com/careers/');
        $urls = array_column($links, 'url');

        $this->testEq('two job links extracted', [
            'https://example.com/jobs/1',
            'https://example.com/roles/lead-engineer-position',
        ], $urls);

        $this->test('relative hrefs resolved to absolute', !in_array('/jobs/1', $urls, true));

        foreach ($links as $link) {
            $this->testEq('posted_hint null for all links', null, $link['posted_hint']);
        }

        $titles = array_column($links, 'title');
        $this->test('title captured from anchor text', in_array('Senior Developer', $titles, true));
    }

    private function runNormalizeCandidateKey(): void
    {
        echo "\n=== candidate key normalization ===\n";

        $this->testEq('trailing slash trimmed', 'https://a.at/jobs/1', GenericListing::normalizeCandidateKey('https://a.at/jobs/1/'));
        $this->testEq('utm params stripped', 'https://a.at/jobs/1', GenericListing::normalizeCandidateKey('https://a.at/jobs/1?utm_source=x'));
        $this->testEq('tracking stripped, others kept', 'https://a.at/jobs/1?page=2', GenericListing::normalizeCandidateKey('https://a.at/jobs/1?page=2&fbclid=abc'));
        $this->testEq('host lowercased', 'https://a.at/jobs/1', GenericListing::normalizeCandidateKey('HTTPS://A.AT/jobs/1'));
        $this->testEq('fragment stripped', 'https://a.at/jobs/1', GenericListing::normalizeCandidateKey('https://a.at/jobs/1#apply'));
    }

    private function runFilterLinks(): void
    {
        echo "\n=== bridge link filtering ===\n";

        $listing = 'https://a.at/careers/';
        $links = [
            ['url' => 'https://a.at/jobs/1', 'text' => 'Senior Dev'],
            ['url' => 'https://a.at/about', 'text' => 'About Us'],
            ['url' => 'https://a.at/careers/', 'text' => 'Careers home'],
            ['url' => 'https://a.at/jobs/1', 'text' => 'Duplicate'],
            ['url' => 'https://a.at/positions/42', 'text' => 'Product Owner'],
            ['url' => '', 'text' => 'No URL'],
        ];

        $filtered = GenericListing::filterLinks($links, $listing);
        $urls = array_column($filtered, 'url');

        $this->testEq('count after filtering', 2, count($filtered));
        $this->test('job signal link kept', in_array('https://a.at/jobs/1', $urls, true));
        $this->test('non-job link skipped', !in_array('https://a.at/about', $urls, true));
        $this->test('listing variant skipped', !in_array('https://a.at/careers/', $urls, true));
        $this->test('absolute url passed through', in_array('https://a.at/positions/42', $urls, true));

        $byUrl = [];
        foreach ($filtered as $l) {
            $byUrl[$l['url']] = $l;
            $this->testEq('posted_hint null', null, $l['posted_hint']);
        }
        $this->testEq('title mapped from text', 'Product Owner', $byUrl['https://a.at/positions/42']['title']);
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
