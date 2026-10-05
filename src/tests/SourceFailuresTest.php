<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\SourceFailures;
use App\Search\UrlIndex;

/**
 * Per-source outcomes for the chat source panel (the P4-S2 agreement: tell the user which sources could not
 * be read, and why). Labels are server-rendered so the live payload and the reloaded view cannot drift, and
 * "read but no usable content" is derived from the evidence rather than stored.
 */
class SourceFailuresTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== Source outcomes (read / could not be read) ===\n";

        // ── labels and solvability ──────────────────────────────────────────
        $this->testEq('a CAPTCHA is labelled as the user\'s to solve',
            'needs your attention in the browser', SourceFailures::label('challenge_required'));
        $this->test('and is marked solvable', SourceFailures::solvable('challenge_required'));
        $this->testEq('a consent wall is its own label',
            'needs a consent click in the browser', SourceFailures::label('consent_required'));
        $this->testEq('a timeout says so', 'timed out', SourceFailures::label('timeout'));
        $this->test('a timeout is retryable', SourceFailures::solvable('timeout'));
        $this->testEq('an unknown status degrades to a readable string',
            'could not be read', SourceFailures::label('something_new_from_the_bridge'));
        $this->test('and is not claimed to be solvable', !SourceFailures::solvable('something_new_from_the_bridge'));

        $e = SourceFailures::entry('https://example.com/a/b?x=1', 'rejected', 'HTTP 403');
        $this->testEq('an entry carries the domain, not the whole URL', 'example.com', $e['domain']);
        $this->testEq('an entry carries the raw status for tracing', 'rejected', $e['status']);
        $this->test('the tooltip keeps the bridge detail',
            str_contains($e['tooltip'], 'HTTP 403'));

        // ── sources that were read and contributed nothing ───────────────────
        $map = [
            'S1' => ['url' => 'https://a.example/one', 'domain' => 'a.example'],
            'S2' => ['url' => 'https://b.example/two', 'domain' => 'b.example'],
            'S5' => ['url' => 'https://c.example/three', 'domain' => 'c.example'],
        ];
        $evidence = '<source id="S1"><domain>a.example</domain><chunk id="S1-C1">x</chunk></source>'
                  . '<source id="S2"><domain>b.example</domain><claim refs="S2-C1">y</claim></source>';
        $unused = SourceFailures::unused($map, $evidence);
        $this->testEq('the source with no evidence block is reported', 1, count($unused));
        $this->testEq('and named by its URL from the source map', 'https://c.example/three', $unused[0]['url'] ?? '');
        $this->testEq('with the honest reason', 'no_usable_content', $unused[0]['status'] ?? '');
        $this->testEq('reading a claim-only source is not a failure', 0,
            count(SourceFailures::unused(['S2' => $map['S2']], $evidence)));
        $this->testEq('an empty evidence block flags every source', 3, count(SourceFailures::unused($map, '')));
        $this->testEq('no sources means nothing to report', [], SourceFailures::unused([], $evidence));

        // ── one list for the panel, de-duplicated by identity ────────────────
        $fetchFailures = [
            ['url' => 'https://b.example/two', 'status' => 'consent_required', 'reason' => 'consent dialog'],
            ['url' => 'https://d.example/four', 'status' => 'timeout', 'reason' => null],
        ];
        $final = SourceFailures::finalize($fetchFailures, $map, $evidence);
        $urls = array_column($final, 'url');
        $this->testEq('fetch failures and unread sources in one list', 3, count($final));
        $this->testEq('a page that failed and is also in the map appears once', 1,
            count(array_keys($urls, 'https://b.example/two', true)));
        $this->testEq('the fetch failure wins over the derived entry',
            'consent_required', $final[0]['status'] ?? '');
        $this->test('the derived one keeps its own reason',
            in_array('no_usable_content', array_column($final, 'status'), true));
        // A clean turn is one where every registered source has an evidence block — S5 has none here, so
        // it must still be reported; with only the covered sources the list is empty.
        $this->testEq('a turn with nothing unread reports nothing', [], SourceFailures::finalize(
            [], ['S1' => $map['S1'], 'S2' => $map['S2']], $evidence));

        // ── the panel's two groups never overlap ─────────────────────────────
        // The map is everything the turn fetched; the panel must not list the same page as both read and
        // unread (measured: every source shown twice, all of them red).
        $panelFailed = SourceFailures::finalize($fetchFailures, $map, $evidence);
        $panelUsed = SourceFailures::used($map, $panelFailed);
        $this->testEq('a page listed as failed is not also listed as successful', 1, count($panelUsed));
        $this->test('and the one that stays is the one that carried evidence', isset($panelUsed['S1']));
        $this->testEq('a turn with no failures keeps every source', $map, SourceFailures::used($map, []));
        $this->testEq('a failed fetch that never entered the map removes nothing', $map,
            SourceFailures::used($map, [['url' => 'https://never.example/x', 'status' => 'timeout']]));
        $this->testEq('an entry with no URL cannot mask a source', count($map),
            count(SourceFailures::used($map, [['status' => 'error']])));

        // ── one row per page, however many ids it got ────────────────────────
        // Measured (session 1001315): two searches in one turn registered the same thread under S1 and S4,
        // and the panel listed it twice as two identical successful sources.
        $twice = [
            'S1' => ['url' => 'https://a.example/one', 'domain' => 'a.example', 'title' => 'One'],
            'S4' => ['url' => 'https://a.example/one', 'domain' => 'a.example', 'title' => 'One'],
            'S2' => ['url' => 'https://b.example/two', 'domain' => 'b.example', 'title' => 'Two'],
        ];
        $deduped = SourceFailures::used($twice, []);
        $this->testEq('a page registered under two ids is listed once', 2, count($deduped));
        $this->testEq('and keeps the first id', 'S1', (string) array_key_first($deduped));

        // ── a failed row says which page, not just which domain ──────────────
        $titled = SourceFailures::unused(['S9' => ['url' => 'https://c.example/three', 'title' => 'Three']], '');
        $this->testEq('a source with no evidence carries its page title', 'Three', $titled[0]['title'] ?? '');
        $untitled = SourceFailures::unused(['S9' => ['url' => 'https://c.example/three']], '');
        $this->testEq('and stays empty when the map has no title', '', $untitled[0]['title'] ?? '');
        $this->testEq('a raw fetch failure has no title to carry', '',
            SourceFailures::entry('https://d.example/four', 'rejected')['title']);

        $this->runSiteGroups();

        echo "\n--- SourceFailures: {$this->passed} passed, {$this->failed} failed ---\n";
        foreach ($this->failures as $f) echo "  FAIL: {$f}\n";
        return $this->failed === 0;
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }

    // ── the panel's rows as site categories ─────────────────────────────
    // Three pages on one host is one source, listed once with its endpoints inside: a turn that reads
    // huggingface.co three times must not read as three sources.
    private function runSiteGroups(): void
    {
        echo "\n=== sources grouped by site ===\n";

        $pages = [
            'S1' => ['url' => 'https://benchlm.ai/models/qwen3-8-flash-next', 'title' => 'Benchmarks', 'domain' => 'benchlm.ai'],
            'S2' => ['url' => 'https://huggingface.co/Qwen/Qwen3.8-Flash-Next', 'title' => 'Model card', 'domain' => 'huggingface.co'],
            'S3' => ['url' => 'https://huggingface.co/Qwen/Qwen3.8-Flash-Next/tree/main', 'title' => 'Files', 'domain' => 'huggingface.co'],
            'S4' => ['url' => 'https://qwen.ai/blog?id=qwen3.8-flash-next', 'title' => 'Qwen', 'domain' => 'qwen.ai'],
            'S5' => ['url' => 'https://www.huggingface.co/Qwen/Qwen3.8-27b', 'title' => 'Older model', 'domain' => 'www.huggingface.co'],
        ];

        $groups = SourceFailures::groupBySite($pages);
        $this->testEq('one group per site', 3, count($groups));
        $this->testEq('sites keep the order the turn read them', ['benchlm.ai', 'huggingface.co', 'qwen.ai'],
            array_column($groups, 'site'));
        $this->testEq('the site that answered three times holds three pages', 3, count($groups[1]['pages']));
        $this->testEq('and keeps every page, in the order read', ['Model card', 'Files', 'Older model'],
            array_column($groups[1]['pages'], 'title'));
        $this->testEq('a single-page site is its own group', ['Qwen'], array_column($groups[2]['pages'], 'title'));
        $this->testEq('a www host is titled without it', 'example.com',
            SourceFailures::groupBySite(['x' => ['url' => 'https://www.example.com/a', 'domain' => 'www.example.com']])[0]['site']);

        $this->testEq('an endpoint is the path without the host', '/Qwen/Qwen3.8-Flash-Next',
            UrlIndex::endpoint('https://huggingface.co/Qwen/Qwen3.8-Flash-Next'));
        $this->testEq('a query is part of the endpoint', '/blog?id=qwen3.8-flash-next',
            UrlIndex::endpoint('https://qwen.ai/blog?id=qwen3.8-flash-next'));
        $this->testEq('a bare host has no endpoint to show', '', UrlIndex::endpoint('https://example.com/'));
        $this->testEq('an unparseable URL has no endpoint', '', UrlIndex::endpoint('not a url'));

        $this->testEq('a source with no site keeps its own group', 1,
            count(SourceFailures::groupBySite(['m1' => ['title' => 'a memory']])));
        $this->testEq('and is not merged with a second one', 2,
            count(SourceFailures::groupBySite(['m1' => ['title' => 'a'], 'm2' => ['title' => 'b']])));
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $this->failures[] = "{$label}: expected {$e}, got {$a}";
        echo "  [FAIL] {$label} — expected {$e}, got {$a}\n";
    }
}