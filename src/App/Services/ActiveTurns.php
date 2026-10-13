<?php

declare(strict_types=1);

namespace App\Services;

use App\Cache;
use Predis\Client;

/**
 * Who is using the engine right now.
 *
 * The inference lock answers "is *somebody* generating"; it does not answer "is a user's turn in flight" — a turn
 * releases the lock between its rounds (tool call, then the answer), so a background job looking only at the lock can
 * start work in that gap and make the turn's next round wait on an atomization it never asked for. The owner's ruling:
 * the current loop outranks everything in the queue, so background work must be able to ask this question directly.
 *
 * A turn touches its entry when it starts and clears it when it ends; the entry also carries a timestamp, so an entry
 * whose process died ages out on its own (a killed request leaves nothing to clear it).
 *
 * See `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
 */
final class ActiveTurns
{
    /** A turn that has not refreshed its heartbeat for this long is presumed dead. TTL on the entry is the same. */
    public const STALE_MS = 20_000;

    private const KEY = 'turns:active';
    private const TTL_SECONDS = 60;

    /** A turn started (or is between rounds). Refreshable; cheap. */
    public static function touch(int $sessionId): void
    {
        self::redis()->zadd(self::KEY, [(string)$sessionId => self::nowMs()]);
        self::redis()->expire(self::KEY, self::TTL_SECONDS);
    }

    /** The turn ended (answered, stopped, or failed). */
    public static function clear(int $sessionId): void
    {
        self::redis()->zrem(self::KEY, (string)$sessionId);
    }

    /** Is any conversation's turn live? This is the gate: while it is true, no lane of the job queue is claimed. */
    public static function any(int $withinMs = self::STALE_MS): bool
    {
        $redis = self::redis();
        $cutoff = self::nowMs() - $withinMs;
        // Drop the dead ones as we look, so a crashed request cannot block the queue forever.
        $stale = $redis->zrangebyscore(self::KEY, '-inf', (string)$cutoff);
        if ($stale !== []) {
            $redis->zrem(self::KEY, ...array_map('strval', $stale));
        }

        return $redis->zcard(self::KEY) > 0;
    }

    /** The conversations with a live turn — for diagnostics and for the owner's "why is my queue not moving". */
    public static function sessions(int $withinMs = self::STALE_MS): array
    {
        $redis = self::redis();
        $cutoff = self::nowMs() - $withinMs;

        return array_map('intval', $redis->zrangebyscore(self::KEY, '(' . $cutoff, '+inf'));
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private static function redis(): Client
    {
        return Cache::getClient();
    }
}
