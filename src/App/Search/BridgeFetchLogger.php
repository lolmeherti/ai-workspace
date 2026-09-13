<?php

namespace App\Search;

use App\Logger;

final class BridgeFetchLogger
{
    /**
     * Record the outcome of a single site fetch in human-readable terms so the
     * event log shows, per site, whether we actually got usable content.
     *
     * Outcome vocabulary:
     *   ok         — fetched + extracted N entities (usable content)
     *   empty      — fetched but no usable entities extracted
     *   blocked    — bot check (challenge/consent) blocked extraction
     *   cooldown   — host was already in cooldown, skipped
     *   timeout    — no response within the bridge timeout
     *   rejected   — URL failed validation (scheme/port/credentials)
     *   http_error — upstream HTTP 4xx/5xx
     *   error      — transport/parse/unknown failure
     */
    public static function record(string $url, string $status, int $bodyLen, int $entityCount): void
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        $outcome = match (true) {
            $status === 'success' && $entityCount > 0 => 'ok',
            $status === 'success'                      => 'empty',
            in_array($status, ['challenge_required', 'consent_required'], true) => 'blocked',
            $status === 'cooldown'                     => 'cooldown',
            $status === 'timeout'                      => 'timeout',
            $status === 'rejected'                     => 'rejected',
            str_starts_with($status, 'http_')          => 'http_error',
            default                                    => 'error',
        };

        $level = match ($outcome) {
            'ok'     => 'info',
            'error'  => 'error',   // transport / invalid-JSON from the bridge itself
            default  => 'warn',    // empty, blocked, cooldown, timeout, rejected, http_error
        };

        Logger::logEvent('bridge_fetch', self::describe($outcome, $domain, $entityCount, $bodyLen, $status), [
            'domain'       => $domain,
            'url'          => $url,
            'status'       => $status,
            'outcome'      => $outcome,
            'body_len'     => $bodyLen,
            'entity_count' => $entityCount,
        ], $level, 'bridge');
    }

    private static function describe(string $outcome, string $domain, int $entityCount, int $bodyLen, string $status): string
    {
        return match ($outcome) {
            'ok'         => "Parsed {$domain}: {$entityCount} entities, {$bodyLen} chars",
            'empty'      => "Parsed {$domain} but extracted no entities (no usable content)",
            'blocked'    => "Blocked by {$domain} ({$status})",
            'cooldown'   => "Skipped {$domain} (host in cooldown)",
            'timeout'    => "Timed out fetching {$domain}",
            'rejected'   => "Rejected {$domain} ({$status})",
            'http_error' => "HTTP {$status} fetching {$domain}",
            'error'      => "Failed to fetch {$domain} ({$status})",
            default      => "{$domain}: {$status}",
        };
    }
}
