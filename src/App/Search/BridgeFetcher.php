<?php

namespace App\Search;

use App\Config;

final class BridgeFetcher
{
    private string $baseUrl;

    public function __construct()
    {
        $host = Config::get('BRIDGE_HOST', 'host.docker.internal');
        $port = (int) Config::get('BRIDGE_HTTP_PORT', 9876);
        $this->baseUrl = "http://{$host}:{$port}";
    }

    public function isConnected(): bool
    {
        $ch = curl_init($this->baseUrl . '/bridge/status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            return false;
        }

        $data = json_decode($body, true);
        return ($data['connected'] ?? false) === true;
    }

    /**
     * Validate URL structure before dispatching to the bridge.
     * Returns null on success, or an error string on failure.
     *
     * Structural checks only — scheme, credentials, host presence, port.
     * DNS resolution and private-IP filtering happen in the Go relay on
     * Windows where the host DNS is the user's real resolver.
     */
    public static function validateFetchUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return 'malformed URL';
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return "rejected scheme: {$scheme}";
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return 'URL contains embedded credentials';
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return 'URL missing host';
        }

        $port = isset($parsed['port'])
            ? (int) $parsed['port']
            : ($scheme === 'https' ? 443 : 80);

        if (!in_array($port, [80, 443], true)) {
            return "rejected port: {$port}";
        }

        return null;
    }

    public function fetch(string $url, ?string $requestId = null): BridgeFetchResult
    {
        $validationError = self::validateFetchUrl($url);
        if ($validationError !== null) {
            return new BridgeFetchResult('rejected', null, $validationError);
        }

        $requestId ??= bin2hex(random_bytes(8));

        $ch = curl_init($this->baseUrl . '/bridge/fetch');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'url'        => $url,
                'request_id' => $requestId,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 92,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '') {
            return new BridgeFetchResult('error', null, $err);
        }
        if ($code >= 400) {
            return new BridgeFetchResult('http_' . $code, null, 'HTTP ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new BridgeFetchResult('parse_failed', null, 'invalid JSON response');
        }

        $status  = $data['status'] ?? 'unknown';
        $content = $data['content'] ?? null;
        $error   = $data['error'] ?? null;

        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $content = $decoded;
            }
        }

        return new BridgeFetchResult($status, $content, $error);
    }

    /** @return Candidate[] */
    public function searchSERP(string $query, ?string $requestId = null): array
    {
        $requestId ??= bin2hex(random_bytes(8));

        $ch = curl_init($this->baseUrl . '/bridge/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'query'      => $query,
                'request_id' => $requestId,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 32,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }
        if (($data['status'] ?? '') !== 'success') {
            return [];
        }

        $candidates = [];
        foreach ($data['candidates'] ?? [] as $c) {
            $candidates[] = new Candidate(
                url:      $c['url'] ?? '',
                title:    $c['title'] ?? '',
                snippet:  $c['snippet'] ?? '',
                domain:   parse_url($c['url'] ?? '', PHP_URL_HOST) ?: '',
                position: $c['position'] ?? 0,
            );
        }
        return $candidates;
    }
}
