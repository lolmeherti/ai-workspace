<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\VideoJobGateway;
use App\Services\VideoWatchRunner;

/**
 * The watch's stop path, without a launcher: the runner is what turns "the browser went away" into
 * "the job was cancelled", and every other exit path must also leave no job running.
 */
final class FakeWatchGateway implements VideoJobGateway
{
    public array $started = [];
    public array $polled = [];
    public array $cancelled = [];
    public array $queue = [];
    public bool $startOk = true;
    public string $startError = 'The launcher is not reachable: connection refused';
    public bool $cancelOk = true;
    public string $cancelError = '';
    public array $pending = ['id' => 'job-1', 'done' => false, 'status' => 'Working…'];

    public function start(string $url): array
    {
        $this->started[] = $url;

        return $this->startOk
            ? ['ok' => true, 'job' => ['id' => 'job-1', 'status' => 'Queued'], 'error' => '']
            : ['ok' => false, 'job' => [], 'error' => $this->startError];
    }

    public function poll(string $jobId): array
    {
        $this->polled[] = $jobId;
        $job = $this->queue !== [] ? array_shift($this->queue) : $this->pending;

        return ['ok' => true, 'job' => $job, 'error' => ''];
    }

    public function cancel(string $jobId): array
    {
        $this->cancelled[] = $jobId;

        return ['ok' => $this->cancelOk, 'error' => $this->cancelError];
    }
}

class VideoWatchRunnerTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runHappyPath();
        $this->runStopPaths();
        $this->runFailurePaths();

        echo "\n  {$this->passed} passed, {$this->failed} failed\n";
        foreach ($this->failures as $failure) {
            echo "    - {$failure}\n";
        }

        return $this->failed === 0;
    }

    private function runHappyPath(): void
    {
        echo "\n=== a watch that finishes ===";

        $gateway = new FakeWatchGateway();
        $gateway->queue = [
            ['id' => 'job-1', 'status' => 'Downloading audio…', 'stage' => 'audio'],
            ['id' => 'job-1', 'status' => 'Transcribing…', 'stage' => 'transcribe', 'step' => 1, 'total' => 3],
            ['id' => 'job-1', 'status' => 'Transcript ready', 'stage' => 'done', 'done' => true, 'result' => ['asr' => [['start' => 0, 'end' => 1, 'text' => 'hello']]]],
        ];
        $runner = $this->runner($gateway);
        $emitted = [];
        $result = $runner->run('https://youtu.be/abc', function (string $text, array $job) use (&$emitted): void {
            $emitted[] = $text;
        }, static fn(): bool => true);

        $this->testEq('the job is started once', ['https://youtu.be/abc'], $gateway->started);
        $this->testEq('it ends done', 'done', $result['status']);
        $this->testEq('and carries the transcript', 'hello', $result['job']['result']['asr'][0]['text'] ?? '');
        $this->testEq('no cancellation happened', [], $gateway->cancelled);
        $this->testEq('the spinner saw every stage', [
            'Downloading audio…',
            'Transcribing… (1 of 3)',
            'Transcript ready',
        ], $emitted);

        // identical consecutive statuses must not spam the stream
        $gateway2 = new FakeWatchGateway();
        $gateway2->queue = [
            ['id' => 'job-1', 'status' => 'Transcribing…'],
            ['id' => 'job-1', 'status' => 'Transcribing…'],
            ['id' => 'job-1', 'status' => 'Transcribing…', 'done' => true],
        ];
        $emitted2 = [];
        $this->runner($gateway2)->run('u', function (string $t) use (&$emitted2): void {
            $emitted2[] = $t;
        }, static fn(): bool => true);
        $this->testEq('a repeated status is emitted once', ['Transcribing…'], $emitted2);
    }

    private function runStopPaths(): void
    {
        echo "\n=== the stop button ===";

        $gateway = new FakeWatchGateway();
        $runner = $this->runner($gateway);
        $connected = true;
        $result = $runner->run('https://youtu.be/abc', static function (): void {}, function () use (&$connected): bool {
            $was = $connected;
            $connected = false;   // the browser goes away after the first iteration

            return $was;
        });

        $this->testEq('a disconnect is reported as disconnected', 'disconnected', $result['status']);
        $this->testEq('and the job is cancelled, not merely abandoned', ['job-1'], $gateway->cancelled);
        $this->test('the job is polled at least once before that', count($gateway->polled) >= 1);

        // a stop that arrives while the job is already finishing still cancels exactly once
        $gateway2 = new FakeWatchGateway();
        $gateway2->queue = [['id' => 'job-1', 'done' => true, 'status' => 'Transcript ready']];
        $gateway2->pending = ['id' => 'job-1', 'done' => true, 'status' => 'Transcript ready'];
        $this->runner($gateway2)->run('u', static function (): void {}, static fn(): bool => false);
        $this->testEq('a stop after the job finished cancels once, never twice', 1, count($gateway2->cancelled));

        $gateway3 = new FakeWatchGateway();
        $gateway3->queue = [['id' => 'job-1', 'done' => true, 'media_left' => 2, 'media_error' => 'the file is in use']];
        $gateway3->pending = ['id' => 'job-1', 'done' => true, 'media_left' => 2];
        $result3 = $this->runner($gateway3, 0.05)->run('u', static function (): void {}, static fn(): bool => false);
        $this->testEq('a stopped job reports the media it could not delete', 2, $result3['job']['media_left'] ?? 0);

        $gateway4 = new FakeWatchGateway();
        $gateway4->cancelOk = false;
        $gateway4->cancelError = 'the launcher is not reachable';
        $result4 = $this->runner($gateway4)->run('u', static function (): void {}, static fn(): bool => false);
        $this->testEq('a cancel that fails is reported, not swallowed', 'the launcher is not reachable', $result4['error']);
    }

    private function runFailurePaths(): void
    {
        echo "\n=== failures leave nothing running ===";

        $unreachable = new FakeWatchGateway();
        $unreachable->startOk = false;
        $result = $this->runner($unreachable)->run('https://youtu.be/abc', static function (): void {}, static fn(): bool => true);
        $this->testEq('a launcher that is not running is a plain failure', 'failed', $result['status']);
        $this->testEq('with the reason the user needs', 'The launcher is not reachable: connection refused', $result['error']);
        $this->testEq('nothing was polled', [], $unreachable->polled);
        $this->testEq('and nothing needed cancelling', [], $unreachable->cancelled);

        $jobFailed = new FakeWatchGateway();
        $jobFailed->queue = [['id' => 'job-1', 'error' => 'YouTube refused the download (HTTP 403)', 'status' => 'Failed']];
        $result2 = $this->runner($jobFailed)->run('u', static function (): void {}, static fn(): bool => true);
        $this->testEq('a failed job is a failed watch', 'failed', $result2['status']);
        $this->testEq('carrying the decoder reason', 'YouTube refused the download (HTTP 403)', $result2['error']);
        $this->testEq('and it is not left running', ['job-1'], $jobFailed->cancelled);

        $timedOut = new FakeWatchGateway();
        $result3 = $this->runner($timedOut, 0.0, 0.0)->run('u', static function (): void {}, static fn(): bool => true);
        $this->testEq('a watch that outlives its deadline fails visibly', 'failed', $result3['status']);
        $this->test('the timeout says so', str_contains($result3['error'], 'timed out'));
        $this->testEq('and the job is cancelled', ['job-1'], $timedOut->cancelled);
    }

    private function runner(FakeWatchGateway $gateway, float $grace = 0.0, float $deadline = 30.0): VideoWatchRunner
    {
        return new VideoWatchRunner($gateway, 1, $deadline, $grace);
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
