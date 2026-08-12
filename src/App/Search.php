<?php

namespace App;

use App\Enums\SearchIntent;
use App\Search\Candidate;

class Search
{
    /**
     * Engine profiles per intent. One SearXNG request may fan out to
     * upstream engines — use a minimal set to avoid spamming from one IP.
     */
    private const ENGINE_PROFILES = [
        'software_docs'  => ['duckduckgo', 'github', 'stackoverflow'],
        'academic'       => ['duckduckgo', 'arxiv', 'pubmed'],
        'news'           => ['bing', 'duckduckgo', 'google_news'],
        'default'        => ['duckduckgo', 'bing'],
    ];

    /**
     * Query SearXNG and return structured Candidate objects.
     *
     * @param string $query The search query
     * @param int $limit Maximum number of candidates to return (default 12)
     * @param SearchIntent|null $intent Intent for engine profile selection
     * @return Candidate[]
     */
    public static function queryCandidates(string $query, int $limit = 12, ?SearchIntent $intent = null): array
    {
        $host = rtrim(getenv('SEARXNG_HOST') ?: 'http://searxng:8080', '/');
        $url = $host . '/search?q=' . urlencode($query) . '&format=json';

        // Engine profile
        $engines = self::ENGINE_PROFILES[$intent?->value] ?? self::ENGINE_PROFILES['default'];
        foreach ($engines as $engine) {
            $url .= '&engines=' . urlencode($engine);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Search: SearXNG returned HTTP {$httpCode} for query: {$query}");
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['results']) || !is_array($data['results'])) {
            return [];
        }

        $candidates = [];
        $position = 0;
        foreach ($data['results'] as $result) {
            $position++;
            if (!isset($result['url'])) {
                continue;
            }

            $domain = parse_url($result['url'], PHP_URL_HOST) ?: 'unknown';

            $candidates[] = new Candidate(
                url: $result['url'],
                title: $result['title'] ?? '',
                snippet: $result['content'] ?? $result['snippet'] ?? '',
                domain: $domain,
                position: $position,
                engine: $result['engine'] ?? null,
                publishedDate: $result['publishedDate'] ?? null,
            );

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * Legacy method — query SearXNG and return plain URL strings.
     * Kept for backward compat with existing SearchWebTool.
     *
     * @param string $query
     * @param int $limit
     * @return array<string> Array of URL strings
     */
    public static function query(string $query, int $limit = 3): array
    {
        $candidates = self::queryCandidates($query, $limit);
        return array_map(fn(Candidate $c) => $c->url, $candidates);
    }
}
