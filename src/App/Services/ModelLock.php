<?php

namespace App\Services;

use App\Cache;
use Predis\Client;

final class ModelLock
{
    public const DEFAULT_TTL_MS = 720_000;
    public const PROCESS_TTL_MS = 1_800_000;

    private const LOCK_KEY = 'inference:lock';
    private const STATUS_PREFIX = 'inference:status:';
    private const OWNER_KEY = 'inference:owner';

    private static ?string $ownerToken = null;

    public static function acquire(int $ttlMs): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = self::redis()->set(self::LOCK_KEY, $token, 'PX', $ttlMs, 'NX');
        if ($acquired) {
            self::$ownerToken = $token;
            // Who is holding it, beside the token. Without this a lock whose holder died is indistinguishable from a lock
            // whose holder is busy, and the only thing that could free it was its TTL — a quarter of an hour in which
            // every turn is refused and every queued turn is claimed and handed straight back.
            self::redis()->setex(self::OWNER_KEY, (int)ceil($ttlMs / 1000), json_encode([
                'token' => $token,
                'pid' => getmypid(),
                'sapi' => PHP_SAPI,
            ]));
            SessionState::publishEngine();

            return $token;
        }
        return null;
    }

    /**
     * Free a lock whose holder process is gone.
     *
     * The holder is a pid in this container, so /proc answers the question directly: a lock recorded by a process that no
     * longer exists is not being held by anybody and will never be released by itself. A lock with no owner record at all
     * is left alone — that is an older lock or a race, and the TTL still covers it.
     */
    public static function reclaimDeadOwner(): bool
    {
        $redis = self::redis();
        $record = $redis->get(self::OWNER_KEY);
        $token = $redis->get(self::LOCK_KEY);
        if ($record === null || $token === null) {
            return false;
        }
        $owner = json_decode((string)$record, true);
        if (!is_array($owner) || ($owner['token'] ?? null) !== $token) {
            return false;
        }
        $pid = (int)($owner['pid'] ?? 0);
        if ($pid > 0 && is_dir('/proc/' . $pid)) {
            return false; // the holder is alive and busy: that is what the lock is for
        }

        $redis->del([self::LOCK_KEY, self::OWNER_KEY, self::STATUS_PREFIX . $token]);
        \App\Logger::logEvent('inference_lock_reclaimed', 'Freed a lock whose holder process is gone', [
            'held_by_pid' => $pid, 'sapi' => (string)($owner['sapi'] ?? ''),
        ], 'warn', 'ModelLock');
        SessionState::publishEngine();

        return true;
    }

    public static function acquireOrBusy(int $ttlMs): string
    {
        $token = self::acquire($ttlMs);
        if ($token === null) {
            throw new ModelBusyException(self::busyMessage());
        }
        return $token;
    }

    public static function renew(string $token, int $ttlMs): bool
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('PEXPIRE', KEYS[1], ARGV[2])
else
    return 0
end
LUA;
        return (bool) self::redis()->eval($script, 1, self::LOCK_KEY, $token, $ttlMs);
    }

    public static function release(string $token): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
else
    return 0
end
LUA;
        self::redis()->eval($script, 1, self::LOCK_KEY, $token);
        // Our own record only: another holder's record must survive us.
        $record = self::redis()->get(self::OWNER_KEY);
        $owner = $record !== null ? json_decode((string)$record, true) : null;
        if (is_array($owner) && ($owner['token'] ?? null) === $token) {
            self::redis()->del(self::OWNER_KEY);
        }
        if (self::$ownerToken === $token) {
            self::$ownerToken = null;
        }
        SessionState::publishEngine();
    }

    public static function isOwner(): bool
    {
        return self::$ownerToken !== null;
    }

    public static function ownerToken(): ?string
    {
        return self::$ownerToken;
    }

    public static function busyMessage(): string
    {
        $token = self::redis()->get(self::LOCK_KEY);
        if ($token !== null) {
            $status = self::redis()->get(self::STATUS_PREFIX . $token);
            if ($status !== null) {
                $data = json_decode($status, true);
                if (is_array($data) && !empty($data['message'])) {
                    return (string) $data['message'];
                }
            }
        }
        return 'AI is busy with another task. Please try again shortly.';
    }

    /** Read ownership and its matching status atomically; never expose the token. */
    public static function availability(): array
    {
        $script = <<<'LUA'
local token = redis.call('GET', KEYS[1])
if not token then return {0, ''} end
return {1, redis.call('GET', ARGV[1] .. token) or ''}
LUA;
        $snapshot = self::redis()->eval($script, 1, self::LOCK_KEY, self::STATUS_PREFIX);
        $status = json_decode($snapshot[1] ?? '', true);
        return ['busy' => (bool)($snapshot[0] ?? false),
            'message' => $status['message'] ?? 'AI is busy with another task.'];
    }

    public static function setStatus(string $token, string $message): void
    {
        self::redis()->setex(self::STATUS_PREFIX . $token, 3600, json_encode(['message' => $message]));
    }

    public static function clearStatus(string $token): void
    {
        self::redis()->del(self::STATUS_PREFIX . $token);
    }

    private static function redis(): Client
    {
        return Cache::getClient();
    }
}
