<?php

declare(strict_types=1);

namespace App\Services;

use App\Logger;

/**
 * Keeps a watch alive while the user watches it: poll the job, publish the spinner's text, and — the
 * part that matters — notice when the browser is gone and stop the job.
 *
 * The stop button aborts the SSE request in the browser. That closes this loop but leaves the job on
 * the launcher, so a disconnect is a cancellation, not just a hang-up: the job is cancelled by id, the
 * child process tree is killed, and the job's own exit path deletes the media (locked decision 16).
 *
 * Every exit path here either completes the job or cancels it. A watch that is left running with
 * nobody listening is the failure this class exists to prevent.
 */
final class VideoWatchRunner
{
    public const POLL_INTERVAL_US = 500000;
    public const DEFAULT_DEADLINE_S = 1800.0;
    public const CANCEL_GRACE_S = 5.0;

    private VideoJobGateway $gateway;
    private int $pollIntervalUs;
    private float $deadlineSeconds;
    private float $cancelGraceSeconds;

    public function __construct(
        VideoJobGateway $gateway,
        int $pollIntervalUs = self::POLL_INTERVAL_US,
        float $deadlineSeconds = self::DEFAULT_DEADLINE_S,
        float $cancelGraceSeconds = self::CANCEL_GRACE_S
    ) {
        $this->gateway = $gateway;
        $this->pollIntervalUs = $pollIntervalUs;
        $this->deadlineSeconds = $deadlineSeconds;
        $this->cancelGraceSeconds = $cancelGraceSeconds;
    }

    /**
     * @param callable(string,array):void $emit            status text + the raw job snapshot
     * @param callable():bool             $stillConnected  false once the browser has gone
     * @return array{status:string, job:array<string,mixed>, error:string}
     *         status: done | cancelled | failed | disconnected
     */
    public function run(string $url, callable $emit, callable $stillConnected): array
    {
        $started = $this->gateway->start($url);
        if (!$started['ok']) {
            return ['status' => 'failed', 'job' => [], 'error' => $started['error']];
        }

        $job = $started['job'];
        $jobId = (string) ($job['id'] ?? '');
        if ($jobId === '') {
            return ['status' => 'failed', 'job' => $job, 'error' => 'The launcher started a job without an id'];
        }

        $lastText = null;
        $deadline = microtime(true) + $this->deadlineSeconds;

        while (true) {
            if (!$stillConnected()) {
                return $this->stop($jobId, 'disconnected');
            }

            $polled = $this->gateway->poll($jobId);
            if (!$polled['ok']) {
                return $this->stop($jobId, 'failed', $polled['error']);
            }

            $job = $polled['job'];
            $text = $this->statusText($job);
            if ($text !== '' && $text !== $lastText) {
                $emit($text, $job);
                $lastText = $text;
            }

            if (!empty($job['error'])) {
                return $this->stop($jobId, 'failed', (string) $job['error']);
            }
            if (!empty($job['done'])) {
                return ['status' => 'done', 'job' => $job, 'error' => ''];
            }
            if (microtime(true) > $deadline) {
                return $this->stop($jobId, 'failed', 'The watch timed out before it produced a transcript');
            }

            usleep($this->pollIntervalUs);
        }
    }

    /**
     * Cancel, then wait briefly for the job to report what it deleted. The wait is bounded because the
     * caller is answering an HTTP request that has already gone away; the job's own exit path does the
     * deleting whether or not anyone is still reading.
     */
    private function stop(string $jobId, string $status, string $error = ''): array
    {
        $cancelled = $this->gateway->cancel($jobId);
        if (!$cancelled['ok']) {
            Logger::logEvent('video_watch_cancel_failed', 'Could not stop the watch job: ' . $cancelled['error'], [
                'job_id' => $jobId,
                'wanted' => $status,
            ], 'error', 'VideoWatchRunner');

            return ['status' => $status, 'job' => [], 'error' => $error !== '' ? $error : $cancelled['error']];
        }

        $deadline = microtime(true) + $this->cancelGraceSeconds;
        $job = [];
        while (microtime(true) < $deadline) {
            $polled = $this->gateway->poll($jobId);
            if (!$polled['ok']) {
                break;
            }
            $job = $polled['job'];
            if (!empty($job['done'])) {
                break;
            }
            usleep($this->pollIntervalUs);
        }

        if (!empty($job['media_left'])) {
            Logger::logEvent('video_watch_media_delete_failed', 'Media survived a stopped watch', [
                'job_id'    => $jobId,
                'left'      => (int) $job['media_left'],
                'error'     => (string) ($job['media_error'] ?? ''),
            ], 'error', 'VideoWatchRunner');
        }

        return ['status' => $status, 'job' => $job, 'error' => $error];
    }

    /** The Go side already writes the human line; this only adds the counter when it has one. */
    private function statusText(array $job): string
    {
        $text = trim((string) ($job['status'] ?? ''));
        $total = (int) ($job['total'] ?? 0);
        if ($text !== '' && $total > 0) {
            $text .= ' (' . (int) ($job['step'] ?? 0) . ' of ' . $total . ')';
        }

        return $text;
    }
}
