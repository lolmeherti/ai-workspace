<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\ChatManager;
use App\Search\SearchPipeline;
use App\Services\VideoJobGateway;
use App\Services\VideoWatchIngest;
use ReflectionMethod;

/**
 * A pasted video link, from the turn's point of view: it is split away from the page path, watched, and its
 * transcript handed over as evidence in the shape a fetched page produces.
 *
 * No launcher, no network, no engine: the job gateway is faked, and the fusion pass is exercised only where
 * it needs no model call (one witness means there is nothing to reconcile).
 */
class VideoWatchIngestTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runSplitting();
        $this->runDocument();
        $this->runIngest();

        echo "\n  {$this->passed} passed, {$this->failed} failed\n";
        foreach ($this->failures as $failure) {
            echo "    - {$failure}\n";
        }

        return $this->failed === 0;
    }

    // ── a video never takes the page route ──────────────────────────────

    private function runSplitting(): void
    {
        echo "\n=== splitting the links ===";

        $split = new ReflectionMethod(ChatManager::class, 'splitVideoUrls');
        $split->setAccessible(true);

        [$videos, $pages] = $split->invoke(null, [
            'https://m.youtube.com/watch?v=abc',
            'https://youtu.be/xyz',
            'https://www.youtube-nocookie.com/embed/q',
            'https://example.com/an-article',
        ], []);

        $this->testEq('every youtube form is a video', [
            'https://m.youtube.com/watch?v=abc',
            'https://youtu.be/xyz',
            'https://www.youtube-nocookie.com/embed/q',
        ], $videos);
        $this->testEq('everything else stays a page', ['https://example.com/an-article'], $pages);

        [, $remaining] = $split->invoke(null, ['https://youtu.be/xyz'], ['https://youtu.be/xyz']);
        $this->testEq('a video already watched this session is not watched again', [], $remaining);

        // The URL is still the citation anchor, so a lookalike host must not be captured as a video.
        [$none, $host] = $split->invoke(null, ['https://notyoutube.com/watch?v=abc'], []);
        $this->testEq('a lookalike host is not a video', [], $none);
        $this->testEq('and it is left to the page path', ['https://notyoutube.com/watch?v=abc'], $host);
    }

    // ── a document this turn made itself becomes evidence ───────────────

    private function runDocument(): void
    {
        echo "\n=== the transcript as evidence ===";

        $pipeline = new SearchPipeline(new AgentManager());
        $pipeline->setSourceStartSeq(3);

        $docs = [[
            'url'         => 'https://youtu.be/xyz',
            'title'       => 'A talk about plastic',
            'domain'      => 'youtu.be',
            'sectionType' => 'transcript',
            'text'        => "Video: A talk about plastic (16:11)\n\n[00:01][merged] hello there\n[00:04][merged] let us begin",
        ]];

        $out = $pipeline->ingestDocument($docs, 'what did they say about plastic?');

        $this->testEq('the transcript is one source', ['S3'], $out['sourceIds']);
        $this->testEq('registered under its own url', 'youtu.be', $out['sourceMap']['S3']['domain'] ?? '');
        $this->test('the spoken words are in the evidence', str_contains((string) $out['evidence'], 'let us begin'));
        $this->test('the header and the lines are separate chunks', count($out['backingChunks']) === 2);

        $pipeline->setSourceStartSeq(4);
        $two = $pipeline->ingestDocument([
            $docs[0],
            ['url' => 'https://youtu.be/abc', 'title' => 'Another', 'domain' => 'youtu.be', 'text' => "[00:02][asr] second video"],
        ], 'compare them');
        $this->testEq('a second video gets the next source id', ['S4', 'S5'], $two['sourceIds']);

        $empty = $pipeline->ingestDocument([], 'nothing here');
    }

    // ── the watch itself, driven by a faked job ────────────────────────

    private function runIngest(): void
    {
        echo "\n=== watching, with a faked job ===";

        $statuses = [];
        $traces = [];
        $emit = function (string $type, array $payload) use (&$statuses, &$traces): void {
            if ($type === 'status') {
                $statuses[] = (string) ($payload['text'] ?? '');
            }
            if ($type === 'trace') {
                $traces[] = (string) ($payload['label'] ?? '');
            }
        };
        $connected = static fn(): bool => true;

        // A video with no captions at all: the transcription is the only witness, so the fusion pass has
        // nothing to reconcile and makes no model call.
        $gateway = new FakeVideoGateway(
            ['ok' => true, 'job' => ['id' => 'w1-1'], 'error' => ''],
            ['ok' => true, 'error' => '', 'job' => [
                'id' => 'w1-1', 'done' => true, 'stage' => 'done', 'status' => 'Transcript ready',
                'media_left' => 0, 'result' => [
                    'outcome' => 'transcribed',
                    'notice'  => '',
                    'metadata' => ['title' => 'Me at the zoo', 'language' => 'en', 'duration' => 19.0],
                    'asr' => [
                        ['start' => 1.0, 'end' => 3.0, 'text' => 'alright so here we are'],
                        ['start' => 3.0, 'end' => 6.0, 'text' => 'in front of the elephants'],
                    ],
                ],
            ]],
        );

        $watch = (new VideoWatchIngest(new AgentManager(), $gateway))->ingest(
            ['https://www.youtube.com/watch?v=jNQXAC9IVRw'], 'what is this about', $emit, $connected
        );

        $this->testEq('the first thing the user sees is that we are watching it', 'Let me watch that video for you…', $statuses[0] ?? '');
        $this->test('the job\'s own status lines are shown', in_array('Transcript ready', $statuses, true));
        $this->testEq('the watch produced one document', 1, $watch['watched']);
        $this->testEq('under the video\'s own title', 'Me at the zoo', $watch['docs'][0]['title']);
        $this->testEq('marked as a transcript', 'transcript', $watch['docs'][0]['sectionType']);
        $this->test('the words are there with their times', str_contains($watch['docs'][0]['text'], 'alright so here we are'));

        // The trace panel is filled while the watch runs, not after it: the turn's own calls have not begun
        // when the watch is over, so without these lines the panel stays empty for the whole watch.
        $this->test('the fusion pass announces itself on the trace',
            in_array('Fusing the captions with the transcription', $traces, true));

        // A watch that fails reports it rather than producing an empty document. The failure rides inside
        // the job snapshot, which is where the launcher puts it.
        $failing = new FakeVideoGateway(
            ['ok' => true, 'job' => ['id' => 'w2-1'], 'error' => ''],
            ['ok' => true, 'error' => '', 'job' => ['id' => 'w2-1', 'done' => false, 'error' => 'the audio could not be downloaded']],
        );
        $failed = (new VideoWatchIngest(new AgentManager(), $failing))->ingest(['https://youtu.be/xyz'], '', $emit, $connected);
        $this->testEq('a failed watch produces no document', 0, $failed['watched']);
        $this->testEq('and is reported', 1, count($failed['failed']));
        $this->testEq('with the reason', 'the audio could not be downloaded', $failed['failed'][0]['error']);

        // A watch that finished with nothing to read is not silently treated as success.
        $blank = new FakeVideoGateway(
            ['ok' => true, 'job' => ['id' => 'w3-1'], 'error' => ''],
            ['ok' => true, 'error' => '', 'job' => ['id' => 'w3-1', 'done' => true, 'result' => ['outcome' => 'captions', 'metadata' => []]]],
        );
        $nothing = (new VideoWatchIngest(new AgentManager(), $blank))->ingest(['https://youtu.be/xyz'], '', $emit, $connected);
        $this->testEq('a watch with nothing to read produces no document', 0, $nothing['watched']);
        $this->testEq('and says it was empty', 'empty', $nothing['failed'][0]['status'] ?? '');

        // A job that is mid-transcription reports its stage, and the trace gets one line for it. The count
        // inside a stage ("transcribing 3 of 51") must not earn a line of its own.
        $running = new FakeVideoGateway(
            ['ok' => true, 'job' => ['id' => 'w4-1'], 'error' => ''],
            ['ok' => true, 'error' => '', 'job' => [
                'id' => 'w4-1', 'done' => true, 'stage' => 'transcribe', 'status' => 'Transcribing 3 of 51…',
                'media_left' => 0, 'result' => [
                    'outcome'  => 'transcribed',
                    'metadata' => ['title' => 'A talk', 'language' => 'en', 'duration' => 19.0],
                    'asr'      => [['start' => 1.0, 'end' => 3.0, 'text' => 'hello there']],
                ],
            ]],
        );
        $traces = [];
        (new VideoWatchIngest(new AgentManager(), $running))->ingest(['https://youtu.be/zzz'], '', $emit, $connected);
        $this->test('a watch in progress names its stage on the trace',
            in_array('Transcribing the video on this machine', $traces, true));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function testEq(string $label, $expected, $actual): void
    {
        if ($expected === $actual) {
            $this->pass($label);

            return;
        }
        $this->fail($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) {
            $this->pass($label);

            return;
        }
        $this->fail($label);
    }

    private function pass(string $label): void
    {
        $this->passed++;
        echo "  [PASS] {$label}\n";
    }

    private function fail(string $label): void
    {
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }
}

/** A job gateway that answers from a fixture, so the turn's video path needs no launcher at all. */
final class FakeVideoGateway implements VideoJobGateway
{
    public function __construct(private array $start, private array $poll) {}

    public function start(string $url): array
    {
        return $this->start;
    }

    public function poll(string $jobId): array
    {
        return $this->poll;
    }

    public function cancel(string $jobId): array
    {
        return ['ok' => true, 'job' => [], 'error' => ''];
    }
}
