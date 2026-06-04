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

    public static function getSearchLedger(): array
    {
        $data = self::get('search_ledger');
        if (!$data) {
            return [];
        }

        $ledger = json_decode($data, true) ?: [];
        $validLedger = [];
        $sevenDaysAgo = time() - (7 * 86400);

        foreach ($ledger as $item) {
            if (isset($item['timestamp']) && $item['timestamp'] > $sevenDaysAgo) {
                $validLedger[] = $item;
            }
        }

        return $validLedger;
    }

    public static function addToLedger(string $query, string $cacheKey): void
    {
        $ledger = self::getSearchLedger();
        
        array_unshift($ledger, [
            'query' => $query,
            'cache_key' => $cacheKey,
            'timestamp' => time(),
            'human_time' => date('l, F j, Y g:i A')
        ]);

        $ledger = array_slice($ledger, 0, 50);
        
        self::set('search_ledger', json_encode($ledger), 604800);
    }
}