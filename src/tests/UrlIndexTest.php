<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\SearchPipeline;
use App\Search\UrlIndex;

/**
 * URL indexing for pasted text: every URL enumerated, the same page counted once, unfetchable links named.
 *
 * These cases pin the rules that keep a pasted page from turning into a fetch storm and keep one URL from
 * being parsed twice (tracking params, fragment, trailing slash, param order, scheme/host case).
 */
class UrlIndexTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        echo "\n=== UrlIndex ===\n";

        // ── enumerate + dedupe ──────────────────────────────────────────────
        $text = "Look at https://example.com/a?utm_source=x and https://Example.COM/a (same page) "
              . "plus https://example.com/b#frag and [markdown](https://example.com/b).";
        $idx = UrlIndex::index($text);
        // four mentions of two pages: /a with tracking params + uppercase host, /b with fragment + markdown
        $this->testEq('four mentions collapse to two pages', 2, $idx['counts']['unique']);
        $this->testEq('duplicates counted, not silently dropped', 2, $idx['counts']['duplicates']);
        $this->testEq('order preserved', ['https://example.com/a', 'https://example.com/b'],
            array_column(array_slice($idx['entries'], 0, 2), 'url'));

        $this->testEq('tracking params stripped from identity',
            UrlIndex::key('https://example.com/a?utm_source=x&utm_medium=y'),
            UrlIndex::key('https://example.com/a'));
        $this->testEq('fragment is not part of identity',
            UrlIndex::key('https://example.com/a#section'),
            UrlIndex::key('https://example.com/a'));
        $this->testEq('trailing slash is not part of identity',
            UrlIndex::key('https://example.com/a/'),
            UrlIndex::key('https://example.com/a'));
        $this->testEq('param order normalised',
            UrlIndex::key('https://example.com/s?b=2&a=1'),
            UrlIndex::key('https://example.com/s?a=1&b=2'));
        $this->testEq('scheme and host case normalised',
            UrlIndex::key('HTTPS://EXAMPLE.com/x'),
            UrlIndex::key('https://example.com/x'));
        $this->testEq('default port removed',
            UrlIndex::key('https://example.com:443/x'),
            UrlIndex::key('https://example.com/x'));
        $this->testNEq('a real path difference stays a difference',
            UrlIndex::key('https://example.com/a'),
            UrlIndex::key('https://example.com/b'));
        $this->testNEq('meaningful query params kept',
            UrlIndex::key('https://example.com/s?page=2'),
            UrlIndex::key('https://example.com/s'));

        // ── sentence punctuation vs URL characters ──────────────────────────
        $this->testEq('sentence full stop dropped', 'https://example.com/x',
            UrlIndex::trimTrailing('https://example.com/x.'));
        $this->testEq('comma after URL dropped', 'https://example.com/x',
            UrlIndex::trimTrailing('https://example.com/x,'));
        $this->testEq('balanced parens kept (wikipedia)',
            'https://en.wikipedia.org/wiki/Llama_(language_model)',
            UrlIndex::trimTrailing('https://en.wikipedia.org/wiki/Llama_(language_model)'));
        $this->testEq('unbalanced closing paren dropped', 'https://example.com/x',
            UrlIndex::trimTrailing('(https://example.com/x)'));
        $this->testEq('wrapping quote dropped', 'https://example.com/x',
            UrlIndex::trimTrailing('"https://example.com/x"'));

        // ── what cannot be fetched, and why ─────────────────────────────────
        $this->testEq('mailto not http', 'scheme-not-http',
            UrlIndex::fetchable('mailto:someone@example.com')[1] ?? null);
        $this->testEq('localhost refused', 'localhost', UrlIndex::fetchable('http://localhost/x')[1] ?? null);
        $this->testEq('private ip refused', 'private-host', UrlIndex::fetchable('http://192.168.1.10/x')[1] ?? null);
        $this->testEq('loopback refused', 'private-host', UrlIndex::fetchable('http://127.0.0.1:8080/')[1] ?? null);
        $this->testEq('file scheme refused', 'scheme-not-http',
            UrlIndex::fetchable('file:///etc/passwd')[1] ?? null);
        $this->testEq('public host allowed', true, UrlIndex::fetchable('https://example.com/x')[0]);
        $this->testEq('public ip allowed', true, UrlIndex::fetchable('https://93.184.216.34/x')[0]);

        $mixed = "https://example.com/a http://localhost:8080/b mailto:x@y.z http://10.0.0.5/c";
        $mx = UrlIndex::index($mixed);
        $this->testEq('only the fetchable one survives', 1, $mx['counts']['fetchable']);
        // mailto: is not extracted at all (only http/https are URLs worth indexing), so three
        // http(s) mentions minus the fetchable one leaves two reported as skipped.
        $this->testEq('unfetchable ones are still reported', 2, $mx['counts']['skipped']);

        // ── nothing gets parsed twice in one session ────────────────────────
        $entries = UrlIndex::index('https://example.com/a https://example.com/b?utm_source=t')['entries'];
        $split = UrlIndex::excluding($entries, ['https://EXAMPLE.com/a/', 'https://example.com/b']);
        $this->testEq('already-fetched URLs are excluded', 0, count($split['fresh']));
        $this->testEq('and reported as already parsed', 2, count($split['already']));
        $split2 = UrlIndex::excluding($entries, ['https://example.com/a']);
        $this->testEq('only the unseen one is fresh', 1, count($split2['fresh']));
        $this->testEq('the fresh one is the b URL', 'https://example.com/b', $split2['fresh'][0]['url']);

        // ── markdown + bare text, no scheme-less guessing ──────────────────
        $md = UrlIndex::index('[a](https://example.com/one) and example.com/two and /relative/path');
        $this->testEq('markdown target indexed', 1, $md['counts']['unique']);
        $this->testEq('scheme-less host is not treated as a URL', 0,
            UrlIndex::index('example.com/two')['counts']['unique']);
        $this->testEq('relative path is not treated as a URL', 0,
            UrlIndex::index('/relative/path')['counts']['unique']);

        // ── defaults the composer strip renders (and the send path applies) ──
        $link = 'https://example.com/a';
        $d = UrlIndex::defaultSelection(UrlIndex::index($link), $link);
        $this->testEq('a bare link defaults to ON', [$link], $d['on']);
        $this->testEq('and is "here is a page"', true, $d['summarize']);
        $this->testEq('flagged as basically a link', true, $d['basically_a_link']);

        $q = "what is this? {$link}";
        $d = UrlIndex::defaultSelection(UrlIndex::index($q), $q);
        $this->testEq('a link with a short question still defaults to ON', [$link], $d['on']);
        $this->testEq('but is NOT "here is a page"', false, $d['summarize']);
        $this->testEq('the question is the prose with the URL removed', 'what is this?', $d['prose']);

        $blob = "pasted page text with lots of prose https://example.com/a https://example.com/b "
              . "https://example.com/c https://example.com/d and more words to make it clearly not a link";
        $d = UrlIndex::defaultSelection(UrlIndex::index($blob), $blob);
        $this->testEq('a pasted page defaults to OFF', [], $d['on']);
        $this->testEq('all four are listed as off', 4, count($d['off']));

        $long = 'https://example.com/a ' . str_repeat('word ', 60);
        $this->testEq('one link plus a paragraph defaults to OFF', [],
            UrlIndex::defaultSelection(UrlIndex::index($long), $long)['on']);
        $this->testEq('unfetchable URLs never default ON', [],
            UrlIndex::defaultSelection(UrlIndex::index('http://localhost:9/x'), 'http://localhost:9/x')['on']);

        // ── one page, one id per turn ───────────────────────────────────────
        // A page read by one sub-query must not be fetched or re-numbered by the next: the decision is URL
        // identity, so every spelling of the same page resolves to the id it already carries.
        $read = [SearchPipeline::turnUrlKey('https://huggingface.co/Qwen/Qwen3.8-Flash-Next') => 'S2'];
        $this->testEq('trailing slash resolves to the page already read', 'S2',
            SearchPipeline::alreadyReadId('https://huggingface.co/Qwen/Qwen3.8-Flash-Next/', $read));
        $this->testEq('www resolves to the same page', 'S2',
            SearchPipeline::alreadyReadId('https://www.huggingface.co/Qwen/Qwen3.8-Flash-Next', $read));
        $this->testEq('a fragment resolves to the same page', 'S2',
            SearchPipeline::alreadyReadId('https://huggingface.co/Qwen/Qwen3.8-Flash-Next#readme', $read));
        $this->testEq('a tracking param resolves to the same page', 'S2',
            SearchPipeline::alreadyReadId('https://huggingface.co/Qwen/Qwen3.8-Flash-Next?utm_source=serp', $read));
        $this->testEq('host case resolves to the same page', 'S2',
            SearchPipeline::alreadyReadId('https://HuggingFace.co/Qwen/Qwen3.8-Flash-Next', $read));
        $this->testEq('a different page is new', null,
            SearchPipeline::alreadyReadId('https://huggingface.co/Qwen/Qwen3.8-27b', $read));
        $this->testEq('an unparseable URL is new', null, SearchPipeline::alreadyReadId('not a url', $read));
        $this->testEq('an empty map reuses nothing', null,
            SearchPipeline::alreadyReadId('https://huggingface.co/Qwen/Qwen3.8-Flash-Next', []));

        // ── a video link is deferred out of web search, never fetched as a page ──
        $candidates = [
            (object)['url' => 'https://example.com/guide'],
            (object)['url' => 'https://www.youtube.com/watch?v=NA9D49kQFuU'],
            (object)['url' => 'https://youtu.be/abc123'],
            (object)['url' => 'https://huggingface.co/Qwen/Qwen3.8-Flash-Next'],
        ];
        $split = SearchPipeline::deferVideoCandidates($candidates);
        $this->testEq('page candidates are kept', ['https://example.com/guide', 'https://huggingface.co/Qwen/Qwen3.8-Flash-Next'],
            array_map(static fn(object $c): string => $c->url, $split['kept']));
        $this->testEq('video candidates are deferred, youtu.be included',
            ['https://www.youtube.com/watch?v=NA9D49kQFuU', 'https://youtu.be/abc123'], $split['deferred']);
        $this->testEq('a full video URL in a question is recognised', ['https://youtu.be/abc123'],
            SearchPipeline::videoReferences('watch this https://youtu.be/abc123 please'));
        $this->testEq('a bare youtube.com/watch reference is recognised',
            ['youtube.com/watch?v=NA9D49kQFuU'], SearchPipeline::videoReferences('youtube.com/watch?v=NA9D49kQFuU video'));
        $this->testEq('an ordinary URL is not a video reference', [],
            SearchPipeline::videoReferences('https://example.com/guide'));
        $this->testEq('a lookalike host is not a video reference', [],
            SearchPipeline::videoReferences('https://notyoutube.com/watch?v=abc123'));
        $this->testNEq('the deferral note tells the model to stop searching it',
            false, str_contains(SearchPipeline::videoDeferralNote(['https://youtu.be/abc123']), 'Do not search'));

        echo "\n--- UrlIndex: {$this->passed} passed, {$this->failed} failed ---\n";
        foreach ($this->failures as $f) echo "  FAIL: {$f}\n";
        return $this->failed === 0;
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
            return;
        }
        $this->failed++;
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $this->failures[] = "{$label}: expected {$e}, got {$a}";
        echo "  [FAIL] {$label} — expected {$e}, got {$a}\n";
    }

    private function testNEq(string $label, mixed $a, mixed $b): void
    {
        if ($a !== $b) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
            return;
        }
        $this->failed++;
        $this->failures[] = "{$label}: values were equal";
        echo "  [FAIL] {$label} — values were equal\n";
    }
}
