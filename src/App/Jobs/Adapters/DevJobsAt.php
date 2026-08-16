<?php

namespace App\Jobs\Adapters;

class DevJobsAt extends GenericListing
{
    private const MAX_PAGES = 1;
    private const DETAIL_PATTERN = '#^/jobs/[^/]+/?$#';

    public function discover(string $listingUrl, callable $progress, callable $isCancelled): array
    {
        $page1 = self::normalizeToPage1($listingUrl);
        $seen = [];
        $emptyStreak = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if ($isCancelled()) {
                break;
            }
            $pageUrl = $page === 1 ? $page1 : self::paginatePath($page1, $page);
            $progress("Fetching listing page {$page}: {$pageUrl}");

            $method = '';
            $links = $this->fetchListingLinks($pageUrl, $method);

            $before = count($seen);
            foreach ($links as $link) {
                if ($this->matchesDetail($link['url'])) {
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
        }

        return array_values($seen);
    }

    public static function normalizeToPage1(string $url): string
    {
        $url = preg_replace('#/\d+/?$#', '/', $url) ?? $url;

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return rtrim($url, '/');
        }
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (['page', 'start', 'offset'] as $key) {
                unset($params[$key]);
            }
            $query = http_build_query($params);
            $url = self::buildUrl($parts, $query === '' ? null : $query);
        }
        return rtrim($url, '/');
    }

    public static function paginatePath(string $page1Url, int $page): string
    {
        return rtrim($page1Url, '/') . '/' . $page;
    }

    private static function buildUrl(array $parts, ?string $query): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $url = $scheme . '://' . $host . $port . $path;
        if ($query !== null && $query !== '') {
            $url .= '?' . $query;
        }
        return $url;
    }

    private function matchesDetail(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return preg_match(self::DETAIL_PATTERN, $path) === 1;
    }
}
