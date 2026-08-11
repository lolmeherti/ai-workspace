<?php

namespace App\Search;

final class CacheKeyBuilder
{
    /**
     * Normalize content before hashing so minor formatting differences
     * (trailing whitespace, line endings) don't cause cache misses.
     */
    public static function normalizeContent(string $content): string
    {
        $normalized = str_replace("\r\n", "\n", $content);
        $normalized = rtrim($normalized);
        return $normalized;
    }

    /**
     * Content-addressable hash of normalized body text.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', self::normalizeContent($content));
    }

    /**
     * Canonical query: lowercase, collapse whitespace, trim.
     */
    public static function canonicalQuery(string $query): string
    {
        $q = mb_strtolower(trim($query));
        $q = preg_replace('/\s+/', ' ', $q);
        return $q;
    }

    /**
     * Canonical URL: lowercase scheme+host, remove default ports,
     * strip fragments, sort query params, strip tracking params.
     */
    public static function canonicalUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);
        $port = isset($parsed['port']) ? (int)$parsed['port'] : null;
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? null;

        $defaultPort = ($scheme === 'https') ? 443 : 80;

        $canonical = $scheme . '://' . $host;
        if ($port !== null && $port !== $defaultPort) {
            $canonical .= ':' . $port;
        }
        $canonical .= $path;

        if ($query !== null) {
            parse_str($query, $params);

            $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
                'utm_content', 'fbclid', 'gclid', 'ref', 'ref_src', 'ref_url',
                '_ga', '_gl', 'mc_cid', 'mc_eid'];
            foreach ($trackingParams as $tp) {
                unset($params[$tp]);
            }

            ksort($params);
            if (!empty($params)) {
                $canonical .= '?' . http_build_query($params);
            }
        }

        return $canonical;
    }

    /**
     * Canonical query + engine config hash.
     */
    public static function serpKey(string $query, string $engineConfig): string
    {
        $input = self::canonicalQuery($query) . '|' . $engineConfig;
        return 'serp:' . hash('sha256', $input);
    }

    /**
     * Document metadata key (by canonical URL).
     */
    public static function docMetaKey(string $url): string
    {
        return 'doc:url:' . hash('sha256', self::canonicalUrl($url));
    }

    /**
     * Raw body key (content-addressed).
     */
    public static function docBodyKey(string $contentHash): string
    {
        return 'doc:body:' . $contentHash;
    }

    /**
     * Extraction cache key (versioned).
     */
    public static function docExtractKey(string $contentHash, int $extractorVersion): string
    {
        return 'doc:extract:' . $contentHash . ':v' . $extractorVersion;
    }

    /**
     * Chunks cache key (versioned).
     */
    public static function docChunksKey(string $extractionHash, int $chunkerVersion): string
    {
        return 'doc:chunks:' . $extractionHash . ':v' . $chunkerVersion;
    }

    /**
     * Evidence packet key — all inputs hashed for cache uniqueness.
     *
     * Source hashes MUST be sorted so order-independent lookup works.
     */
    public static function evidenceKey(
        array $sourceContentHashes,
        int $retrievalVersion,
        int $chunkerVersion,
        int $extractorVersion,
        int $condenserPromptVersion,
        string $condenserModel,
        int $tokenBudget,
        string $language
    ): string {
        sort($sourceContentHashes);
        $input = implode(',', $sourceContentHashes)
            . '|r' . $retrievalVersion
            . '|c' . $chunkerVersion
            . '|e' . $extractorVersion
            . '|p' . $condenserPromptVersion
            . '|m' . $condenserModel
            . '|b' . $tokenBudget
            . '|l' . $language;

        return 'evidence:' . hash('sha256', $input);
    }

    /**
     * Negative cache key (method-specific).
     */
    public static function negativeCacheKey(string $url): string
    {
        return 'doc:url:' . hash('sha256', self::canonicalUrl($url)) . ':negative';
    }

    /**
     * Reference count key for a content hash.
     */
    public static function refCountKey(string $contentHash): string
    {
        return 'doc:body:' . $contentHash . ':refs';
    }

    /**
     * LRU tracking key (Redis sorted set, score = last access timestamp).
     */
    public const LRU_ZSET = 'cache:body:lru';
}
