<?php

namespace App\Search;

use App\Enums\SearchIntent;

final class CacheTTL
{
    /** TTLs in seconds per intent. */
    public const TTL_STABLE_DOCS     = 604800;   // 7 days (specs, API docs)
    public const TTL_STABLE_DOCS_MAX = 2592000;  // 30 days
    public const TTL_PRODUCT_SPECS   = 86400;    // 1 day
    public const TTL_PRODUCT_SPECS_MAX = 604800; // 7 days
    public const TTL_GENERAL         = 86400;    // 1 day
    public const TTL_GENERAL_MAX     = 604800;   // 7 days
    public const TTL_NEWS            = 900;      // 15 min
    public const TTL_NEWS_MAX        = 3600;     // 60 min
    public const TTL_SERP            = 300;      // 5 min
    public const TTL_SERP_MAX        = 1800;     // 30 min

    /**
     * Get the base TTL in seconds for a content type.
     */
    public static function forIntent(SearchIntent $intent): int
    {
        return match ($intent) {
            SearchIntent::SoftwareDocs => self::TTL_STABLE_DOCS,
            SearchIntent::Academic     => self::TTL_STABLE_DOCS,
            SearchIntent::ProductSpecs => self::TTL_PRODUCT_SPECS,
            SearchIntent::News         => self::TTL_NEWS,
            SearchIntent::Recommendation => self::TTL_GENERAL,
            SearchIntent::General      => self::TTL_GENERAL,
        };
    }

    /**
     * Get the maximum TTL in seconds for a content type
     * (upper bound — even if revalidated-to-fresh repeatedly).
     */
    public static function forIntentMax(SearchIntent $intent): int
    {
        return match ($intent) {
            SearchIntent::SoftwareDocs => self::TTL_STABLE_DOCS_MAX,
            SearchIntent::Academic     => self::TTL_STABLE_DOCS_MAX,
            SearchIntent::ProductSpecs => self::TTL_PRODUCT_SPECS_MAX,
            SearchIntent::News         => self::TTL_NEWS_MAX,
            SearchIntent::Recommendation => self::TTL_GENERAL_MAX,
            SearchIntent::General      => self::TTL_GENERAL_MAX,
        };
    }

    /**
     * Get TTL for SERP results.
     */
    public static function forSerp(): int
    {
        return self::TTL_SERP;
    }

    /**
     * Check if a cached document is stale based on its fetched_at time.
     */
    public static function isStale(string $fetchedAt, SearchIntent $intent): bool
    {
        $age = time() - strtotime($fetchedAt);
        return $age > self::forIntent($intent);
    }

    /**
     * Check if a cached document should be force-expired
     * regardless of revalidation (absolute max age).
     */
    public static function isForceExpired(string $fetchedAt, SearchIntent $intent): bool
    {
        $age = time() - strtotime($fetchedAt);
        return $age > self::forIntentMax($intent);
    }

    /**
     * Estimate topic volatility from query text.
     *
     * Returns "low", "medium", or "high".
     */
    public static function estimateVolatility(string $query): string
    {
        $lower = strtolower($query);

        $highPatterns = [
            '/\b(today|now|current|latest|live|breaking|just (in|announced|released)|this (hour|minute|morning|afternoon|evening))\b/',
            '/\b(stock|price|rate|exchange|crypto|bitcoin|ethereum|nasdaq|dow|s&p)\b/',
            '/\b(score|game|match|tournament|standings|fixture|result)\b/',
            '/\b(weather|forecast|temperature)\b/',
            '/\b(traffic|delay|outage|down|incident)\b/',
            '/\b(election|poll|vote|approval rating)\b/',
        ];

        $mediumPatterns = [
            '/\b(product|buy|price|deal|sale|discount|available|in stock)\b/',
            '/\b(review|rating|comparison|versus|vs)\b/',
            '/\b(new|recent|update|version|release|launch)\b/',
            '/\b(hire|job|career|opening|position)\b/',
        ];

        foreach ($highPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return 'high';
            }
        }

        foreach ($mediumPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return 'medium';
            }
        }

        return 'low';
    }
}
