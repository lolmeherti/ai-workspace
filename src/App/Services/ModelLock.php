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

    private static ?string $ownerToken = null;

    public static function acquire(int $ttlMs): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = self::redis()->set(self::LOCK_KEY, $token, 'PX', $ttlMs, 'NX');
        if ($acquired) {
            self::$ownerToken = $token;
            return $token;
        }
        return null;
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
        if (self::$ownerToken === $token) {
            self::$ownerToken = null;
        }
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
