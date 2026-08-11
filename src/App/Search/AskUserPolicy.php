<?php

namespace App\Search;

final class AskUserPolicy
{
    /**
     * Evaluate whether to AUTO_USE cached content, ASK_USER, or NONE (force live).
     *
     * Returns one of: 'AUTO_USE', 'ASK_USER', 'NONE'
     *
     * @param array $cachedEntry Metadata from the cached entry (must have 'fetched_at')
     * @param string $query The user's search query
     */
    public function evaluate(array $cachedEntry, string $query): string
    {
        if (empty($cachedEntry['fetched_at'])) {
            return 'NONE';
        }

        $age = time() - strtotime($cachedEntry['fetched_at']);
        $volatility = CacheTTL::estimateVolatility($query);

        return match ($volatility) {
            'high' => $this->evaluateHighVolatility($age),
            'medium' => $this->evaluateMediumVolatility($age),
            'low' => $this->evaluateLowVolatility($age),
        };
    }

    /**
     * Check if cached content should be used automatically (AUTO_USE).
     */
    public function shouldAutoUse(array $cachedEntry, string $query): bool
    {
        return $this->evaluate($cachedEntry, $query) === 'AUTO_USE';
    }

    /**
     * Check if the user should be asked (ASK_USER).
     */
    public function shouldAskUser(array $cachedEntry, string $query): bool
    {
        return $this->evaluate($cachedEntry, $query) === 'ASK_USER';
    }

    /**
     * Check if cached content is too stale and live search is required (NONE).
     */
    public function shouldForceLive(array $cachedEntry, string $query): bool
    {
        return $this->evaluate($cachedEntry, $query) === 'NONE';
    }

    /**
     * Get the auto-use age threshold in seconds for a query.
     * Content younger than this can be used without asking.
     */
    public function getAutoUseAge(string $query): int
    {
        $volatility = CacheTTL::estimateVolatility($query);

        return match ($volatility) {
            'high'   => 300,    // 5 minutes
            'medium' => 1800,   // 30 minutes
            'low'    => 21600,  // 6 hours
        };
    }

    /**
     * Get the ask-user age threshold in seconds for a query.
     * Content older than this should force live search.
     */
    public function getAskUserAge(string $query): int
    {
        $volatility = CacheTTL::estimateVolatility($query);

        return match ($volatility) {
            'high'   => 900,    // 15 minutes
            'medium' => 3600,   // 1 hour
            'low'    => 86400,  // 24 hours
        };
    }

    // ── Private ────────────────────────────────────────────────────

    private function evaluateHighVolatility(int $age): string
    {
        if ($age <= 300) {
            return 'AUTO_USE';
        }
        if ($age <= 900) {
            return 'ASK_USER';
        }
        return 'NONE';
    }

    private function evaluateMediumVolatility(int $age): string
    {
        if ($age <= 1800) {
            return 'AUTO_USE';
        }
        if ($age <= 3600) {
            return 'ASK_USER';
        }
        return 'NONE';
    }

    private function evaluateLowVolatility(int $age): string
    {
        if ($age <= 21600) {
            return 'AUTO_USE';
        }
        if ($age <= 86400) {
            return 'ASK_USER';
        }
        return 'NONE';
    }
}
