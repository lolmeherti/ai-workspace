<?php

namespace App\Search;

use App\Cache;
use Predis\Client;

final class OutboundScheduler
{
    private const GLOBAL_DELAY_MS = 800;
    private const PER_HOST_DELAY_MS = 1500;
    private const JITTER_MAX_MS = 700;

    private Client $redis;

    public function __construct()
    {
        $this->redis = Cache::getClient();
    }

    /**
     * Atomically reserve a scheduled slot and return the millisecond
     * timestamp when the request should start.
     *
     * Lua script guarantees no race between concurrent PHP-FPM workers.
     */
    public function acquireSlot(string $host): int
    {
        $now = self::msNow();

        $script = <<<'LUA'
            local global_key = KEYS[1]
            local host_key = KEYS[2]
            local now = tonumber(ARGV[1])
            local global_delay = tonumber(ARGV[2])
            local host_delay = tonumber(ARGV[3])
            local jitter = tonumber(ARGV[4])

            local g_next = tonumber(redis.call('GET', global_key) or 0)
            local h_next = tonumber(redis.call('GET', host_key) or 0)

            local earliest = math.max(now, g_next, h_next)
            local scheduled = earliest + jitter

            redis.call('SET', global_key, scheduled + global_delay)
            redis.call('SET', host_key, scheduled + host_delay)

            return scheduled
        LUA;

        return (int) $this->redis->eval(
            $script,
            2,
            'outbound:global:next_allowed_at',
            "outbound:host:{$host}:next_allowed_at",
            $now,
            self::GLOBAL_DELAY_MS,
            self::PER_HOST_DELAY_MS,
            random_int(0, self::JITTER_MAX_MS),
        );
    }

    /**
     * Reserve a slot and sleep until it's time to proceed.
     */
    public function waitForSlot(string $host): void
    {
        $scheduled = $this->acquireSlot($host);
        $now = self::msNow();

        if ($scheduled > $now) {
            usleep(($scheduled - $now) * 1000);
        }
    }

    /**
     * Acquire a global in-flight lock. Returns a token (opaque string) on
     * success, or null if the lock could not be acquired.
     *
     * The lock is acquired AFTER waiting for the slot, not before — acquiring
     * before a long wait risks the lease expiring before the request starts.
     */
    public function acquireGlobalLock(int $timeoutMs = 30000): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = $this->redis->set('outbound:global:lock', $token, 'PX', $timeoutMs, 'NX');
        return $acquired ? $token : null;
    }

    /**
     * Release the global lock ONLY if the caller owns it (prevents TOCTOU).
     * Returns true if the lock was released.
     */
    public function releaseGlobalLock(string $token): bool
    {
        $script = <<<'LUA'
            if redis.call('GET', KEYS[1]) == ARGV[1] then
                return redis.call('DEL', KEYS[1])
            else
                return 0
            end
        LUA;
        return (bool) $this->redis->eval($script, 1, 'outbound:global:lock', $token);
    }

    /**
     * Convenience: wait for slot, acquire lock (retrying locally on failure),
     * perform the request, release lock. Returns the lock token so callers
     * can release in finally blocks.
     *
     * Usage:
     *   $token = $scheduler->acquireWithWait($host, 60000);
     *   try {
     *       $result = (new BridgeFetcher())->fetch($url);
     *   } finally {
     *       $scheduler->releaseGlobalLock($token);
     *   }
     *
     * @param int $lockTimeoutMs Should exceed the longest expected request.
     */
    public function acquireWithWait(string $host, int $lockTimeoutMs = 30000): string
    {
        $this->waitForSlot($host);

        $token = null;
        while ($token === null) {
            $token = $this->acquireGlobalLock($lockTimeoutMs);
            if ($token === null) {
                usleep(100_000);  // 100ms backoff
            }
        }

        return $token;
    }

    private static function msNow(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
