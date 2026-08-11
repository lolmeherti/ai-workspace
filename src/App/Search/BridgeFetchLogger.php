<?php

namespace App\Search;

use App\Logger;

final class BridgeFetchLogger
{
    private const EMPTY_THRESHOLD = 100;

    public static function record(string $url, string $status, int $bodyLen, int $entityCount): void
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $empty  = $bodyLen < self::EMPTY_THRESHOLD;

        Logger::logEvent(
            'bridge_fetch',
            $status,
            [
                'domain'       => $domain,
                'url'          => $url,
                'body_len'     => $bodyLen,
                'entity_count' => $entityCount,
                'empty'        => $empty,
            ],
            $empty ? 'warn' : 'info',
            'bridge'
        );
    }
}
