<?php

namespace App\Search;

use App\Cache;
use App\Config;
use App\Logger;
use Predis\Client;

final class HostCooldown
{
    private const DEFAULT_COOLDOWN_SECONDS = 7200;
    private const BLOCK_SIGNALS = ['challenge_required', 'consent_required'];

    private Client $redis;

    public function __construct()
    {
        $this->redis = Cache::getClient();
    }

    public static function isBlockSignal(string $status): bool
    {
        return in_array($status, self::BLOCK_SIGNALS, true);
    }

    public function trip(string $host, string $status): void
    {
        $seconds = (int) Config::get('BRIDGE_COOLDOWN_SECONDS', self::DEFAULT_COOLDOWN_SECONDS);
        if ($seconds <= 0) {
            return;
        }
        $this->redis->setex("outbound:host:{$host}:cooldown", $seconds, $status);
        Logger::logEvent('bridge_host_cooldown', "Host cooling down: {$host}", [
            'host' => $host,
            'status' => $status,
            'cooldown_seconds' => $seconds,
        ], 'warn', 'HostCooldown');
    }

    public function isCoolingDown(string $host): bool
    {
        return (bool) $this->redis->exists("outbound:host:{$host}:cooldown");
    }
}
