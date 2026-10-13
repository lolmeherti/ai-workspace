<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

/**
 * The state of one conversation's background work — and the channel that pushes it to open pages.
 *
 * Two things used to be polled for this (the availability pill every 2.5 s while busy, the pipeline every 2 s), which cost
 * the server ~450 ms per request for a value that changes a handful of times per turn (measured). Instead, every point
 * that *changes* the state publishes it here, and `src/state-stream.php` relays the stream to whichever pages are open.
 *
 * The payload is defined once, in `payload()`, and both callers use it: the read-only status endpoint (for a client with
 * no stream, and for tests) and the publisher. One definition, so a pushed state and a polled state can never disagree.
 *
 * Publishing is fire-and-forget: it runs inside the queue's own critical paths, so it must never throw and never recurse
 * (`payload()` only reads).
 */
final class SessionState
{
    /** One channel for the whole app; the payload names its conversation, and pages ignore other conversations' events. */
    public const CHANNEL = 0;

    public static function stream(): TurnStream
    {
        return new TurnStream('state_stream');
    }

    /** The status of one conversation: everything the chat window needs to know about work in flight. */
    public static function payload(int $sessionId): array
    {
        $occupancy = ModelLock::availability();

        $open = ['pending' => [], 'claimed' => []];
        if ($sessionId > 0) {
            try {
                $open = (new JobQueue())->openJobsForSession($sessionId);
            } catch (\Throwable $e) {
                // Redis trouble must not make the whole status read fail: reporting "no jobs known" is better than
                // handing the UI an error it cannot act on.
                $open = ['pending' => [], 'claimed' => []];
            }
        }

        $rows = [];
        foreach (array_merge($open['pending'], $open['claimed']) as $job) {
            foreach ((array)($job['payload']['row_ids'] ?? []) as $rowId) {
                $rows[] = (int)$rowId;
            }
        }

        // Messages the user sent while the engine was busy and that nothing has answered yet. The UI needs this to say
        // "your message is queued" with a number, and to know when that batch has been answered.
        $held = 0;
        if ($sessionId > 0) {
            try {
                $held = (new PendingPromptQueue())->size($sessionId);
            } catch (\Throwable $e) {
                $held = 0;
            }
        }

        // A token that changes exactly when this conversation's notes are built. The drawer's HTML is server-rendered, so
        // an open page cannot see a background enrichment finish on its own; the client watches this and re-reads the row
        // it was showing as "atomizing".
        $evidenceVersion = 0;
        if ($sessionId > 0) {
            try {
                $versionRow = (new Database())->query(
                    "SELECT COALESCE(MAX(id), 0) AS v FROM app_events
                      WHERE session_id = ? AND event_type = 'enrichment_job_finished'",
                    [$sessionId]
                );
                $evidenceVersion = (int)($versionRow[0]['v'] ?? 0);
            } catch (\Throwable $e) {
                $evidenceVersion = 0;   // a status read must not fail over its extra token
            }
        }

        return [
            'status' => 'success',
            'session_id' => (int)$sessionId,
            'working' => $open['pending'] !== [] || $open['claimed'] !== [],
            'queued_jobs' => count($open['pending']),
            'running_jobs' => count($open['claimed']),
            'rows' => array_values(array_unique($rows)),
            'held' => $held,
            'evidence_version' => $evidenceVersion,
            'engine_busy' => (bool)$occupancy['busy'],
            // Only meaningful while something holds the lock: `availability()` hands back a default sentence when the
            // engine is free, and echoing that would tell the UI the AI is busy when it is not.
            'engine_message' => $occupancy['busy'] ? (string)$occupancy['message'] : '',
        ];
    }

    /**
     * Push the state of one conversation to whoever is listening.
     *
     * Never throws: this is called from inside job claims, releases and completions, and a status push that could fail a
     * turn would be worse than a stale badge.
     */
    public static function publish(int $sessionId): void
    {
        try {
            self::stream()->publish(self::CHANNEL, 'state', self::payload($sessionId));
        } catch (\Throwable $e) {
            Logger::logEvent('state_publish_failed', 'Could not publish the conversation state: ' . $e->getMessage(), [
                'session_id' => $sessionId,
            ], 'warn', 'SessionState');
        }
    }

    /**
     * Push the engine's own state (who holds the model lock, and what they are doing).
     *
     * Global, not per conversation: the lock is one engine, so a page in conversation A cares when conversation B's turn
     * takes it — that is exactly when the pill should say "it will be your turn soon".
     */
    public static function publishEngine(): void
    {
        try {
            $occupancy = ModelLock::availability();
            self::stream()->publish(self::CHANNEL, 'engine', [
                'busy' => (bool)$occupancy['busy'],
                'message' => $occupancy['busy'] ? (string)$occupancy['message'] : '',
            ]);
        } catch (\Throwable $e) {
            Logger::logEvent('state_publish_failed', 'Could not publish the engine state: ' . $e->getMessage(), [], 'warn', 'SessionState');
        }
    }
}
