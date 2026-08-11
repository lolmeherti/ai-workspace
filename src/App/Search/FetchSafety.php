<?php

namespace App\Search;

class FetchSafety
{
    private const DEFAULT_MAX_BYTES = 5_242_880;  // 5 MB
    private const DEFAULT_MAX_REDIRECTS = 5;
    private const DEFAULT_TIMEOUT = 10;
    private const ALLOWED_PORTS = [80, 443];

    /**
     * Fetch a URL safely: validate URL, resolve DNS (A + AAAA),
     * pin curl to the resolved IP to prevent TOCTOU rebinding,
     * handle redirects in a PHP loop (re-validating each hop).
     *
     * @return FetchResult
     * @throws UnsafeUrlException
     * @throws FetchException
     */
    public static function safeFetchUrl(
        string $url,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        int $maxRedirects = self::DEFAULT_MAX_REDIRECTS
    ): FetchResult {
        $currentUrl = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            $parsed = parse_url($currentUrl);

            if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
                throw new UnsafeUrlException("Invalid scheme: {$currentUrl}");
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                throw new UnsafeUrlException("URL credentials rejected: {$currentUrl}");
            }

            $host = $parsed['host'] ?? '';
            if ($host === '') {
                throw new UnsafeUrlException("Missing host in URL: {$currentUrl}");
            }

            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
            if (!in_array((int)$port, self::ALLOWED_PORTS, true)) {
                throw new UnsafeUrlException("Port {$port} not allowed: {$currentUrl}");
            }

            $validatedIp = self::resolvePublicIp($host);
            if (!$validatedIp) {
                throw new UnsafeUrlException("Host {$host} resolves to private/reserved IP or could not be resolved");
            }

            $isIpv6 = filter_var($validatedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            $resolveEntry = $isIpv6
                ? "{$host}:{$port}:[{$validatedIp}]"
                : "{$host}:{$port}:{$validatedIp}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $currentUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE        => [$resolveEntry],
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.8',
                    'Cache-Control: no-cache',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                ],
                CURLOPT_TIMEOUT        => self::DEFAULT_TIMEOUT,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_XFERINFOFUNCTION => function (
                    $ch,
                    float $downloadTotal,
                    float $downloadedNow,
                    float $uploadTotal,
                    float $uploadedNow
                ) use ($maxBytes): int {
                    return $downloadedNow > $maxBytes ? 1 : 0;
                },
            ]);

            $body = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $currentUrl;
            curl_close($ch);

            if ($body === false || $body === true) {
                throw new FetchException("curl_exec failed for {$currentUrl}");
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                $redirectUrl = self::extractRedirectUrl($ch, $parsed, $host);
                if ($redirectUrl === null) {
                    throw new FetchException("Redirect without Location header for {$currentUrl}");
                }
                $currentUrl = $redirectUrl;
                continue;
            }

            return new FetchResult(
                statusCode: $statusCode,
                body: $body,
                finalUrl: $effectiveUrl,
                resolvedIp: $validatedIp,
                contentType: $contentType,
            );
        }

        throw new FetchException("Too many redirects for {$url}");
    }

    /**
     * Check whether an HTML response looks like a bot-protection challenge page.
     */
    public static function looksLikeChallengePage(string $html): bool
    {
        $lower = strtolower($html);
        $indicators = [
            'cf-browser-verification',
            'attention required',
            'captcha',
            'cf-challenge',
            'ddos-guard',
            'checking your browser',
            'enable javascript',
            'please turn javascript on',
            'prove your humanity',
            'recaptcha',
        ];

        foreach ($indicators as $indicator) {
            if (str_contains($lower, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a fetch result is useful (non-empty, not a challenge page,
     * reasonable content length).
     */
    public static function isUseful(FetchResult $result, int $minChars = 200): bool
    {
        if ($result->statusCode < 200 || $result->statusCode >= 300) {
            return false;
        }

        if (empty($result->body)) {
            return false;
        }

        // Gate-pages (JS skeletons) can be 8KB+ of script/style with almost no
        // prose. strip_tags() keeps script/style inner content, so strip those
        // elements first, collapse whitespace, then check what remains.
        $noScript = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $result->body);
        $noStyle  = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $noScript);
        $visible  = trim(preg_replace('/\s+/', ' ', strip_tags($noStyle)));
        if (mb_strlen($visible) < $minChars) {
            return false;
        }

        if (self::looksLikeChallengePage($result->body)) {
            return false;
        }

        return true;
    }

    /**
     * Decide whether to escalate a failed direct curl to FlareSolverr,
     * or skip this candidate and move to the next.
     *
     * Escalation justified when: user explicitly requested that URL,
     * it's the only authoritative source, or it's the last remaining candidate.
     */
    public static function resolveFetchStrategy(
        Candidate $candidate,
        int $remainingCandidates,
        bool $isExplicitUrlRequest,
        bool $isUniquelyAuthoritative
    ): string {
        $shouldEscalate = $isExplicitUrlRequest
            || $isUniquelyAuthoritative
            || ($remainingCandidates === 0);

        return $shouldEscalate ? 'escalate_to_flaresolverr' : 'skip_candidate';
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Resolve ALL IPv4 and IPv6 records, filter to public IPs only.
     * Returns the first valid public IP, or null.
     */
    private static function resolvePublicIp(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (empty($records)) {
            return null;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Extract a redirect URL from curl info, resolving relative locations.
     */
    private static function extractRedirectUrl(\CurlHandle $ch, array $parsed, string $host): ?string
    {
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if (empty($redirectUrl)) {
            // Try Location header
            return null;
        }

        if (!preg_match('#^https?://#i', $redirectUrl)) {
            $scheme = $parsed['scheme'] ?? 'https';
            $redirectUrl = $scheme . '://' . $host . '/' . ltrim($redirectUrl, '/');
        }

        return $redirectUrl;
    }
}
