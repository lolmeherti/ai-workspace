<?php

namespace App\Search;

final class CandidateDeduplicator
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'gclsrc', 'dclid',
        'ref', 'referrer', 'source',
        'mc_cid', 'mc_eid',
        '_ga', '_gl',
    ];

    private const MAX_PER_DOMAIN = 4;

    /**
     * Deduplicate and filter candidates.
     *
     * @param Candidate[] $candidates
     * @return Candidate[]
     */
    public function deduplicate(array $candidates): array
    {
        $seen = [];
        $seenTitles = [];
        $domainCounts = [];
        $result = [];

        foreach ($candidates as $c) {
            $canonical = $this->canonicalizeUrl($c->url);

            // Exact URL dedup
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;

            // Near-duplicate title detection — requires corroboration
            // to avoid killing distinct pages with generic titles like
            // "Installation" or "README" on different sites.
            $normalizedTitle = $this->normalizeTitle($c->title);
            $snippetWords = $this->contentWords($c->snippet);
            $pathPrefix = $this->pathPrefix($canonical);

            if (isset($seenTitles[$normalizedTitle])) {
                $isDuplicate = false;
                foreach ($seenTitles[$normalizedTitle] as $prev) {
                    if ($this->sharePathPrefix($pathPrefix, $prev['path_prefix'], 2)) {
                        $isDuplicate = true;
                        break;
                    }
                    $overlap = count(array_intersect($snippetWords, $prev['snippet_words']));
                    if ($overlap >= 3) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if ($isDuplicate) {
                    continue;
                }
            }
            $seenTitles[$normalizedTitle][] = [
                'path_prefix'   => $pathPrefix,
                'snippet_words' => $snippetWords,
            ];

            // Per-domain cap
            $domainCounts[$c->domain] = ($domainCounts[$c->domain] ?? 0) + 1;
            if ($domainCounts[$c->domain] > self::MAX_PER_DOMAIN) {
                continue;
            }

            $result[] = $c;
        }

        return $result;
    }

    /**
     * Extract the first N directory segments from a canonicalized URL path
     * for same-page detection. Strips trailing filename/index segments.
     */
    private function pathPrefix(string $canonicalUrl): array
    {
        $path = parse_url($canonicalUrl, PHP_URL_PATH) ?: '/';
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        // Drop terminal file-like segments (containing . or common index names)
        while (!empty($segments)) {
            $last = end($segments);
            if (str_contains($last, '.') || in_array(strtolower($last), ['index', 'default', 'main'])) {
                array_pop($segments);
            } else {
                break;
            }
        }
        return $segments;
    }

    /**
     * Check whether two path prefixes share at least $min common segments
     * from the start.
     */
    private function sharePathPrefix(array $a, array $b, int $min): bool
    {
        $common = 0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] === $b[$i]) {
                $common++;
            } else {
                break;
            }
        }
        return $common >= $min;
    }

    /**
     * Extract meaningful content words from a snippet: lowercase,
     * 4+ characters, no stopwords.
     */
    private function contentWords(string $text): array
    {
        $words = preg_split('/\s+/', strtolower($text));
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'have',
                  'are', 'was', 'were', 'been', 'will', 'would', 'could',
                  'should', 'what', 'when', 'where', 'which', 'who', 'how',
                  'not', 'but', 'all', 'can', 'its', 'has', 'had', 'more',
                  'some', 'than', 'then', 'also', 'just', 'like', 'into',
                  'over', 'after', 'before', 'such', 'only', 'other'];
        $stopSet = array_flip($stop);

        $result = [];
        foreach ($words as $w) {
            $w = trim($w, ".,;:!?\"'()-–—");
            if (strlen($w) >= 4 && !isset($stopSet[$w])) {
                $result[] = $w;
            }
        }
        return array_unique($result);
    }

    /**
     * Canonicalize a URL before comparison. No network activity.
     */
    public function canonicalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);
        $port = $parsed['port'] ?? null;

        // Remove default ports
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parsed['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        $query = $parsed['query'] ?? '';
        if ($query !== '') {
            $query = $this->stripTrackingParams($query);
        }

        $canonical = $scheme . '://' . $host;
        if ($port !== null) {
            $canonical .= ':' . $port;
        }
        $canonical .= $path;
        if ($query !== '') {
            $canonical .= '?' . $query;
        }

        return $canonical;
    }

    /**
     * Normalize a title for near-duplicate detection.
     */
    private function normalizeTitle(string $title): string
    {
        $normalized = strtolower(trim($title));
        // Strip common suffix noise
        $normalized = preg_replace('/\s*[-–|]\s*(.+?)(news|blog|forum|wiki|docs?)$/i', '', $normalized);
        // Collapse whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    /**
     * Strip known tracking parameters from a query string.
     */
    private function stripTrackingParams(string $query): string
    {
        parse_str($query, $params);
        foreach (self::TRACKING_PARAMS as $param) {
            unset($params[$param]);
        }
        return http_build_query($params);
    }
}
