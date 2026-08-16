<?php

namespace App\Jobs\Adapters;

use App\Jobs\JobSourceAdapter;
use App\Logger;
use App\Scraper;
use App\Search\BridgeFetcher;
use App\Search\BridgeFetchLogger;
use App\Search\HostCooldown;
use DOMDocument;

class GenericListing implements JobSourceAdapter
{
    private const JOB_PATH_SIGNALS = [
        '/job', '/career', '/position', '/opening', '/vacancy', '/vacancies',
        '/vacature', '/role', '/stellen', '/stelle', '/karriere', '/offers',
        '/hiring', '/talent',
    ];

    private const MAX_PAGES = 1;

    public function discover(string $listingUrl, callable $progress, callable $isCancelled): array
    {
        $seen = [];
        $visited = [];
        $pageUrl = $listingUrl;
        $emptyStreak = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if ($isCancelled()) {
                break;
            }
            $progress("Fetching listing page {$page}: {$pageUrl}");

            $method = '';
            $rawHtml = null;
            $links = $this->fetchListingLinks($pageUrl, $method, $rawHtml);
            $visited[$pageUrl] = true;

            $before = count($seen);
            foreach ($links as $link) {
                if (!self::isListingVariant($link['url'], $pageUrl)) {
                    $seen[self::normalizeCandidateKey($link['url'])] = $link;
                }
            }

            if (count($seen) === $before) {
                $emptyStreak++;
                if ($emptyStreak >= 2) {
                    break;
                }
            } else {
                $emptyStreak = 0;
            }

            $next = $rawHtml === null ? null : self::nextPageLink($rawHtml, $pageUrl);
            if ($next === null || isset($visited[$next])) {
                break;
            }
            $pageUrl = $next;
        }

        return array_values($seen);
    }

    public static function filterLinks(array $links, string $listingUrl): array
    {
        $out = [];
        foreach ($links as $l) {
            $url = trim((string) ($l['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (!self::looksLikeJobLink($url)) {
                continue;
            }
            if (self::isListingVariant($url, $listingUrl)) {
                continue;
            }
            $out[self::normalizeCandidateKey($url)] = [
                'url'   => $url,
                'title' => trim((string) ($l['text'] ?? '')),
                'posted_hint' => null,
            ];
        }
        return array_values($out);
    }

    protected function fetchListingLinks(string $listingUrl, ?string &$method, ?string &$rawHtml = null): array
    {
        $bridge = new BridgeFetcher();
        $method = 'scraper';
        $links = null;

        if ($bridge->isConnected()) {
            $result = $bridge->fetch($listingUrl);
            if ($result->isSuccess() && is_array($result->content)
                && array_key_exists('links', $result->content)) {
                $method = 'bridge';
                $bodyLen = 0;
                foreach ($result->content['entities'] ?? [] as $e) {
                    $bodyLen += strlen($e['body'] ?? '');
                }
                BridgeFetchLogger::record(
                    $listingUrl,
                    $result->status,
                    $bodyLen,
                    count($result->content['entities'] ?? []),
                );
                $links = self::filterLinks($result->content['links'] ?? [], $listingUrl);
            } elseif ($result->status === 'cooldown' || HostCooldown::isBlockSignal($result->status)) {
                $method = 'blocked';
                Logger::logEvent('job_listing_blocked', 'Listing host blocked/challenged, skipping', [
                    'url' => $listingUrl,
                    'status' => $result->status,
                ], 'warn', 'GenericListing');
                return [];
            } else {
                Logger::logEvent('job_listing_bridge_fail', 'Bridge fetch failed, falling back to Scraper', [
                    'url' => $listingUrl,
                    'status' => $result->status,
                    'error' => $result->error,
                ], 'warn', 'GenericListing');
            }
        }

        if ($links === null) {
            if ((new HostCooldown())->isCoolingDown(parse_url($listingUrl, PHP_URL_HOST) ?: 'unknown')) {
                return [];
            }
            $method = 'scraper';
            $html = Scraper::fetchRaw($listingUrl);
            if ($html === null) {
                throw new \RuntimeException("Fetch failed: {$listingUrl}");
            }
            $rawHtml = $html->body;
            $links = self::extractJobLinks($html->body, $listingUrl);
        }

        Logger::logEvent('job_listing_fetch', 'Listing links fetched', [
            'url' => $listingUrl,
            'method' => $method,
            'links' => count($links),
        ], $links === [] ? 'warn' : 'info', 'GenericListing');

        return $links;
    }

    public static function extractJobLinks(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        libxml_clear_errors();

        $seen = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $resolved = self::resolveUrl($baseUrl, $href);
            if ($resolved === null || !self::looksLikeJobLink($resolved)) {
                continue;
            }

            if (!isset($seen[$resolved])) {
                $seen[$resolved] = [
                    'url' => $resolved,
                    'title' => trim($anchor->textContent),
                    'posted_hint' => null,
                ];
            }
        }

        return array_values($seen);
    }

    public static function resolveUrl(string $baseUrl, string $href): ?string
    {
        $href = trim($href);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
            return self::cleanUrl($href);
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return self::cleanUrl($scheme . ':' . $href);
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $scheme . '://' . $host . $port;

        if (str_starts_with($href, '?')) {
            $path = $parts['path'] ?? '/';
            return self::cleanUrl($base . $path . $href);
        }

        if (str_starts_with($href, '/')) {
            return self::cleanUrl($base . $href);
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return self::cleanUrl($base . $dir . $href);
    }

    public static function normalizeCandidateKey(string $url): string
    {
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        $url = rtrim($url, '/');

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            foreach ($params as $k => $v) {
                if (preg_match('/^(utm_|fbclid|gclid|ref$)/i', (string)$k)) {
                    unset($params[$k]);
                }
            }
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        return $scheme . '://' . $host . $path . $query;
    }

    private static function looksLikeJobLink(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (self::JOB_PATH_SIGNALS as $signal) {
            if (str_contains($path, $signal)) {
                return true;
            }
        }
        return false;
    }

    private static function cleanUrl(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if ($fragment !== null && $fragment !== '') {
            $url = preg_replace('/#.*$/', '', $url);
        }

        return $url;
    }

    private static function isListingVariant(string $url, string $listingUrl): bool
    {
        $urlPath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $listingPath = rtrim((string) parse_url($listingUrl, PHP_URL_PATH), '/');

        if ($urlPath === $listingPath) {
            return true;
        }

        $prefix = $listingPath . '/';
        if (str_starts_with($urlPath, $prefix)) {
            $rest = substr($urlPath, strlen($prefix));
            if ($rest !== '' && ctype_digit($rest)) {
                return true;
            }
        }

        return false;
    }

    private static function nextPageLink(string $html, string $currentUrl): ?string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        libxml_clear_errors();

        foreach (['link', 'a'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                if (strtolower(trim($el->getAttribute('rel'))) === 'next') {
                    $href = trim($el->getAttribute('href'));
                    if ($href !== '') {
                        $resolved = self::resolveUrl($currentUrl, $href);
                        if ($resolved !== null && $resolved !== $currentUrl) {
                            return $resolved;
                        }
                    }
                }
            }
        }

        $currentPage = self::pageFromUrl($currentUrl);
        $nextPage = $currentPage + 1;
        $basePath = rtrim((string) parse_url($currentUrl, PHP_URL_PATH), '/');

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }
            $resolved = self::resolveUrl($currentUrl, $href);
            if ($resolved === null || $resolved === $currentUrl) {
                continue;
            }
            $resolvedPath = rtrim((string) parse_url($resolved, PHP_URL_PATH), '/');
            if ($resolvedPath === $basePath . '/' . $nextPage) {
                return $resolved;
            }
            if ($resolvedPath === $basePath && self::pageFromUrl($resolved) === $nextPage) {
                return $resolved;
            }
        }

        return null;
    }

    private static function pageFromUrl(string $url): int
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            if (isset($params['page']) && ctype_digit((string) $params['page'])) {
                return (int) $params['page'];
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = rtrim($path, '/');
        $last = substr($path, (int) strrpos($path, '/') + 1);
        if ($last !== '' && ctype_digit($last)) {
            return (int) $last;
        }

        return 1;
    }
}
