<?php

namespace App\Search;

/**
 * Deterministic Reddit URL policy.
 *
 * Reddit's own JSON endpoint is the cheapest and richest way to read a thread:
 * one request, no page render, and structured post/comment entities that carry
 * author, score and created_utc (per-item dates). Reading the rendered page
 * instead costs a full document render, and when the JSON path fails the DOM
 * fallback degrades to a single blob holding the whole page with no authors,
 * no scores and no dates.
 *
 * Thread URLs are therefore rewritten to the canonical www .json endpoint
 * before anything navigates. The originating host does not matter; what matters
 * is that the request stays same-origin with the tab, which the canonical www
 * URL guarantees once the tab itself is pointing at it.
 *
 * Not a thread (subreddit listing, user page, unrelated URL) => null, and the
 * caller leaves the URL alone. Non-Reddit hosts are never touched.
 */
final class RedditUrl
{
    /** Hosts that serve threads. A thread is identified by its path, not the host. */
    private const HOSTS = [
        'reddit.com', 'www.reddit.com', 'old.reddit.com', 'np.reddit.com',
        'm.reddit.com', 'new.reddit.com', 'redd.it', 'www.redd.it',
    ];

    /** Hosts whose paths are bare ids (short links) rather than /r/.../comments/... */
    private const SHORT_HOSTS = ['redd.it', 'www.redd.it'];

    /**
     * Canonical .json endpoint for a thread URL, or null when the URL is not a
     * Reddit thread.
     */
    public static function toJson(string $url): ?string
    {
        $parts = self::threadParts($url);
        if ($parts === null) {
            return null;
        }

        return self::threadUrl($parts[0], $parts[1]) . '.json';
    }

    /**
     * Human-readable thread URL (no .json suffix). Used for citations and for
     * anything the user reads; the .json form is an implementation detail of
     * fetching, never a source the user should be shown.
     */
    public static function human(string $url): ?string
    {
        $parts = self::threadParts($url);
        if ($parts === null) {
            return null;
        }

        return self::threadUrl($parts[0], $parts[1]);
    }

    /**
     * @return array{0: ?string, 1: string}|null  [subreddit (null for short links), post id]
     */
    private static function threadParts(string $url): ?array
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return null;
        }

        $host = strtolower($parsed['host']);
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = $parsed['path'] ?? '';

        // /r/<sub>/comments/<id>[/<slug>][/.json]
        if (preg_match('#^/r/([A-Za-z0-9_]+)/comments/([A-Za-z0-9]+)(?:/|$)#', $path, $m)) {
            return [$m[1], $m[2]];
        }

        // redd.it/<id> — no subreddit in the URL
        if (in_array($host, self::SHORT_HOSTS, true) && preg_match('#^/([A-Za-z0-9]{4,})(?:/|$)#', $path, $m)) {
            return [null, $m[1]];
        }

        return null;
    }

    private static function threadUrl(?string $subreddit, string $postId): string
    {
        return $subreddit !== null
            ? "https://www.reddit.com/r/{$subreddit}/comments/{$postId}/"
            : "https://www.reddit.com/comments/{$postId}/";
    }
}
