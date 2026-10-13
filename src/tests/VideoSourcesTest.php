<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\UrlIndex;
use App\Search\VideoSources;

/**
 * The hard map of supported video hosts, and the consequence that matters: a video URL is not a page, so the
 * page path cannot scrape it and hand the scrape over as the video's content.
 */
class VideoSourcesTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runMap();
        $this->runLookalikes();
        $this->runRouting();

        echo "\n  {$this->passed} passed, {$this->failed} failed\n";
        foreach ($this->failures as $failure) {
            echo "    - {$failure}\n";
        }

        return $this->failed === 0;
    }

    private function runMap(): void
    {
        echo "\n=== the declared map ===";


        $watch = VideoSources::match('https://www.youtube.com/watch?v=oOzTaK143hs');
        $this->testEq('a watch link is the youtube platform', 'youtube', $watch['platform'] ?? '');
        $this->testEq('and it is the watch pipeline', 'watch', $watch['pipeline'] ?? '');
        $this->testEq('with the video id pulled out', 'oOzTaK143hs', $watch['video_id'] ?? '');

        foreach (['m.youtube.com', 'music.youtube.com', 'www.youtube.com', 'YOUTUBE.COM'] as $host) {
            $this->test("a subdomain is the same platform: {$host}",
                VideoSources::isVideo("https://{$host}/watch?v=abc"));
        }

        $this->testEq('youtu.be carries the id in its path', 'xyz123', VideoSources::match('https://youtu.be/xyz123')['video_id'] ?? '');
        $this->testEq('and a timestamp does not confuse it', 'xyz123', VideoSources::match('https://youtu.be/xyz123?t=30')['video_id'] ?? '');
        $this->testEq('shorts are videos', 'abc', VideoSources::match('https://www.youtube.com/shorts/abc')['video_id'] ?? '');
        $this->testEq('so are live links', 'abc', VideoSources::match('https://www.youtube.com/live/abc')['video_id'] ?? '');
        $this->testEq('and embeds', 'abc', VideoSources::match('https://www.youtube-nocookie.com/embed/abc')['video_id'] ?? '');

        $this->testEq('a reddit video link is not claimed yet — parked, not mis-served', null, VideoSources::match('https://v.redd.it/abc123'));
        $this->testEq('a reddit thread stays a page', null, VideoSources::match('https://www.reddit.com/r/x/comments/y/title/'));
        $this->testEq('and an image host is not a video', null, VideoSources::match('https://i.redd.it/abc.jpg'));
    }

    private function runLookalikes(): void
    {
        echo "\n=== no lookalike may match ===";

        foreach ([
            'https://notyoutube.com/watch?v=abc',
            'https://youtube.com.evil.io/watch?v=abc',
            'https://evil-youtube.com/watch?v=abc',
            'https://myyoutube.com/watch?v=abc',
            'https://youtube.co/watch?v=abc',
        ] as $url) {
            $this->testEq("refused: {$url}", null, VideoSources::match($url));
        }
    }

    private function runRouting(): void
    {
        echo "\n=== the page path must not touch a video ===";

        $this->testEq('a youtube link is not page-fetchable', [false, 'video'], UrlIndex::fetchable('https://www.youtube.com/watch?v=oOzTaK143hs'));
        $this->testEq('nor is the mobile host', [false, 'video'], UrlIndex::fetchable('https://m.youtube.com/watch?v=abc'));
        $this->testEq('nor is a short link', [false, 'video'], UrlIndex::fetchable('https://youtu.be/abc'));
        $this->testEq('an ordinary page is still fetchable', [true, null], UrlIndex::fetchable('https://example.com/article'));
        $this->testEq('a reddit thread is untouched', [true, null], UrlIndex::fetchable('https://www.reddit.com/r/x/comments/y/title/'));
        $this->testEq('and a reddit video link is untouched until its handler exists', [true, null], UrlIndex::fetchable('https://v.redd.it/abc'));

        $index = UrlIndex::index('https://www.youtube.com/watch?v=oOzTaK143hs');
        $this->testEq('the link is still listed as a source', 1, count($index['entries']));
        $this->testEq('but marked as a video, with the reason kept for the panel', 'video', $index['entries'][0]['reason']);
        $this->testEq('so nothing is fetchable from it', 0, $index['counts']['fetchable']);
        // A video is not a page, but it is still claimed by the turn that pasted it: the watch path opens it.
        $this->test('a bare video link is opened, by the watch path rather than the page path',
            UrlIndex::defaultSelection($index, 'https://www.youtube.com/watch?v=oOzTaK143hs')['basically_a_link'] === true);

        $pageIndex = UrlIndex::index('https://example.com/article');
        $this->test('an ordinary bare link still opens itself',
            UrlIndex::defaultSelection($pageIndex, 'https://example.com/article')['basically_a_link'] === true);
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
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
