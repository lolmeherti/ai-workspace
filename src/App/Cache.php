<?php

namespace App;

use Predis\Client;

class Cache
{
    private static ?Client $instance = null;

    public static function getClient(): Client
    {
        if (self::$instance === null) {
            self::$instance = new Client([
                'scheme' => 'tcp',
                'host'   => Config::get('REDIS_HOST', 'redis'),
                'port'   => Config::get('REDIS_PORT', 6379),
            ]);
        }
        return self::$instance;
    }

    public static function get(string $key): ?string
    {
        return self::getClient()->get($key);
    }

    public static function set(string $key, string $value, int $ttl = 604800): void
    {
        self::getClient()->setex($key, $ttl, $value);
    }

    public static function delete(string $key): void
    {
        self::getClient()->del($key);
    }
}
