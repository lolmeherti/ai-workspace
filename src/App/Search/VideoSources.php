<?php

declare(strict_types=1);

namespace App\Search;

/**
 * The supported video hosts, declared.
 *
 * A URL on one of these domains — or on any subdomain of one — belongs to the video pipeline, not the page
 * pipeline. A hard map, not a heuristic: m.youtube.com, music.youtube.com, youtube-nocookie.com and youtu.be
 * are the same platform as youtube.com, and no lookalike host may match (`matches()`).
 *
 * Why it exists at this level: `UrlIndex::fetchable` consults it and marks a matched URL *not page-fetchable*.
 * That is what stops the page path from scraping a video link and handing the scrape over as if it were the
 * video's content — the failure where a YouTube watch page's title, description and chapter list became the
 * "evidence" for "what does this video say". The video pipeline claims these URLs instead, and the URL itself
 * stays the source's citation anchor.
 *
 * Extend the map only when its handler is real: an entry ahead of its handler turns a URL from "we cannot read
 * this" into "we say nothing", which is worse. `reddit.com` stays a page (the thread `.json` route owns it) and
 * `v.redd.it` is parked until a Reddit handler exists.
 */
final class VideoSources
{
    /**
     * domain => platform + pipeline. A subdomain of a declared domain is the same platform.
     *
     * YouTube only, deliberately: this map is the dispatch point, and an entry whose handler does not exist
     * yet makes those URLs unreadable instead of merely unhandled. Reddit (v.redd.it), Amazon product videos
     * and anything else become further entries when their handler is real — see the plan's "Deferred".
     */
    private const DOMAINS = [
        'youtube.com'          => ['platform' => 'youtube', 'pipeline' => 'watch'],
        'youtu.be'             => ['platform' => 'youtube', 'pipeline' => 'watch'],
        'youtube-nocookie.com' => ['platform' => 'youtube', 'pipeline' => 'watch'],
    ];

    /**
     * @return array{platform:string, pipeline:string, domain:string, host:string, video_id:?string}|null
     */
    public static function match(string $url): ?array
    {
        $host = self::host($url);
        if ($host === null) {
            return null;
        }

        foreach (self::DOMAINS as $domain => $meta) {
            if (self::matches($host, $domain)) {
                return [
                    'platform' => $meta['platform'],
                    'pipeline' => $meta['pipeline'],
                    'domain'   => $domain,
                    'host'     => $host,
                    'video_id' => self::videoId($host, $url, $meta['platform']),
                ];
            }
        }

        return null;
    }

    public static function isVideo(string $url): bool
    {
        return self::match($url) !== null;
    }

    /** @return array<int,string> the declared domains, for the disclosure copy and for tests. */
    public static function domains(): array
    {
        return array_keys(self::DOMAINS);
    }

    /** Exact domain, or a real subdomain of it — never a lookalike: notyoutube.com, youtube.com.evil.io, evil-youtube.com. */
    private static function matches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private static function host(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return strtolower(rtrim((string) $parts['host'], '.'));
    }

    private static function videoId(string $host, string $url, string $platform): ?string
    {
        if ($platform !== 'youtube') {
            return null;
        }

        $parts = parse_url(trim($url));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be') {
            return $path === '' ? null : explode('/', $path)[0];
        }

        foreach (['shorts/', 'live/', 'embed/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));

                return $rest === '' ? null : explode('/', $rest)[0];
            }
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['v', 'vi'] as $key) {
            if (!empty($query[$key]) && is_string($query[$key])) {
                return $query[$key];
            }
        }

        return null;
    }
}
