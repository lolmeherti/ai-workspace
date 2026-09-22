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
     *   ok            — fetched + extracted N entities (usable content)
     *   empty         — fetched but no usable entities extracted
     *   blocked       — bot check (challenge/consent) blocked extraction
     *   cooldown      — host was already in cooldown, skipped
     *   disconnected  — extension not connected / dropped mid-dispatch (browser never navigated)
     *   ws_error      — WebSocket write to the extension failed (browser never navigated)
     *   timeout       — page load hung past the deadline (browser did navigate)
     *   extract_error — content script threw while extracting (browser navigated; extractor bug)
     *   rejected      — URL failed validation (scheme/port/credentials/DNS)
     *   busy          — extension was mid-job (single-slot contention)
     *   http_error    — upstream HTTP 4xx/5xx
     *   error         — transport/parse/unknown failure
     */
    public static function record(string $url, string $status, int $bodyLen, int $entityCount, ?string $error = null, ?array $content = null): void
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        $outcome = match (true) {
            $status === 'success' && $entityCount > 0 => 'ok',
            $status === 'success'                      => 'empty',
            in_array($status, ['challenge_required', 'consent_required'], true) => 'blocked',
            $status === 'cooldown'                     => 'cooldown',
            $status === 'disconnected'                 => 'disconnected',
            $status === 'ws_error'                     => 'ws_error',
            $status === 'deadline' || $status === 'timeout' => 'timeout',
            $status === 'extract_error'                => 'extract_error',
            $status === 'rejected' || $status === 'rejected_redirect' => 'rejected',
            $status === 'busy'                         => 'busy',
            str_starts_with($status, 'http_')          => 'http_error',
            default                                    => 'error',
        };

        $level = match ($outcome) {
            'ok'            => 'info',
            'extract_error' => 'error',
            'error'         => 'error',
            default         => 'warn',
        };

        $detail = self::detailFor($status, $content);

        Logger::logEvent('bridge_fetch', self::describe($outcome, $domain, $entityCount, $bodyLen, $status, $error, $detail), [
            'domain'       => $domain,
            'url'          => $url,
            'status'       => $status,
            'outcome'      => $outcome,
            'body_len'     => $bodyLen,
            'entity_count' => $entityCount,
            'error'        => $error,
            'detail'       => $detail,
        ], $level, 'bridge');
    }

    /**
     * Surface the reason carried on a non-success result (challenge reason,
     * consent state, extractor exception) so failures aren't reduced to a bare
     * status label.
     */
    private static function detailFor(string $status, ?array $content): ?string
    {
        if ($content === null) {
            return null;
        }
        if (is_string($content['_challenge_reason'] ?? null) && $content['_challenge_reason'] !== '') {
            return $content['_challenge_reason'];
        }
        if (is_string($content['_error'] ?? null) && $content['_error'] !== '') {
            return $content['_error'];
        }
        if (is_string($content['_consent'] ?? null) && $content['_consent'] !== '') {
            return 'consent: ' . $content['_consent'];
        }
        return null;
    }

    private static function describe(string $outcome, string $domain, int $entityCount, int $bodyLen, string $status, ?string $error, ?string $detail): string
    {
        $suffix = self::suffix($error, $detail);
        return match ($outcome) {
            'ok'            => "Parsed {$domain}: {$entityCount} entities, {$bodyLen} chars",
            'empty'         => "Parsed {$domain} but extracted no entities (no usable content)",
            'blocked'       => "Blocked by {$domain} ({$status}){$suffix}",
            'cooldown'      => "Skipped {$domain} (host in cooldown)",
            'disconnected'  => "Bridge disconnected fetching {$domain}{$suffix}",
            'ws_error'      => "Bridge write failed fetching {$domain}{$suffix}",
            'timeout'       => "Timed out fetching {$domain}{$suffix}",
            'extract_error' => "Extractor error on {$domain}{$suffix}",
            'rejected'      => "Rejected {$domain} ({$status}){$suffix}",
            'busy'          => "Bridge busy fetching {$domain}",
            'http_error'    => "HTTP {$status} fetching {$domain}",
            'error'         => "Failed to fetch {$domain} ({$status}){$suffix}",
            default         => "{$domain}: {$status}",
        };
    }

    private static function suffix(?string $error, ?string $detail): string
    {
        $parts = [];
        if ($error !== null && $error !== '') {
            $parts[] = $error;
        }
        if ($detail !== null && $detail !== '') {
            $parts[] = $detail;
        }
        return $parts === [] ? '' : ' — ' . implode('; ', $parts);
    }
}
