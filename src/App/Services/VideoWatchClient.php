<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

/**
 * HTTP client for the launcher's /video/watch endpoints (see "Go — /video/watch" in
 * `.hermes/plans/ui/youtube-watcher.md`). Same host/port resolution as BridgeFetcher, because it is
 * the same relay: one place decides where the launcher is.
 */
final class VideoWatchClient implements VideoJobGateway
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $host = Config::get('BRIDGE_HOST', 'host.docker.internal');
        $port = (int) Config::get('BRIDGE_HTTP_PORT', 9876);
        $this->baseUrl = $baseUrl ?? "http://{$host}:{$port}";
    }

    public function start(string $url): array
    {
        return $this->request('POST', '/video/watch', ['url' => $url]);
    }

    public function poll(string $jobId): array
    {
        return $this->request('GET', '/video/watch/' . rawurlencode($jobId));
    }

    public function cancel(string $jobId): array
    {
        $result = $this->request('POST', '/video/watch/' . rawurlencode($jobId) . '/cancel');

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /** @return array{ok:bool, job:array<string,mixed>, error:string} */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            return ['ok' => false, 'job' => [], 'error' => 'The launcher is not reachable: ' . $curlError];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'job' => [], 'error' => "The launcher refused the request (HTTP {$status})"];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'job' => [], 'error' => 'The launcher returned an unreadable answer'];
        }

        return ['ok' => true, 'job' => $decoded, 'error' => ''];
    }
}
