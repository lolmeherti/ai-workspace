<?php

declare(strict_types=1);

namespace App\Agents;

/**
 * Decides WHEN to run the deferred evidence-atomization pass, using thresholds
 * that scale with the active context size (piecewise-linear interpolation over
 * anchor points — NOT a single fixed percentage, and NOT a universal constant).
 *
 * Scheduling policy ONLY — it does not change what the atomizer does. The
 * atomic unit stays ONE tool result at a time (one `data_fetching` row → one
 * `condenseBatched`); this class only decides whether a backlog of un-atomized
 * raw evidence has grown enough (or context headroom is tight enough) to
 * justify spending the consolidation inference(s).
 *
 * Trigger (OR of two signals, both context-scaled, no per-search evidence-size
 * threshold):
 *   - backlog: the session's accumulated un-atomized raw evidence (sum of
 *     `token_estimate` over rows with `atomic_context IS NULL` and
 *     `raw_evicted = 0`) reaches backlogThresholdForContext(ctxSize).
 *   - safety: remaining context headroom falls below
 *     safetyHeadroomForContext(ctxSize) — atomize early to reclaim before the
 *     overflow wall.
 */
final class AtomizationPolicy
{
    /**
     * Backlog-threshold anchor points for piecewise-linear interpolation.
     * [ctx_size_tokens, backlog_threshold_tokens]. Initial defaults, not sacred:
     * 8k→1.5k, 25k→8k, 160k→30k.
     */
    private const BACKLOG_ANCHORS = [
        [8000, 1500],
        [25000, 8000],
        [160000, 30000],
    ];

    /**
     * Safety-headroom anchor points for piecewise-linear interpolation.
     * [ctx_size_tokens, safety_headroom_tokens]. Initial defaults, not sacred.
     * At 160k this preserves the previous 8192 default; smaller contexts scale
     * down so the reserve never exceeds the whole context.
     */
    private const SAFETY_HEADROOM_ANCHORS = [
        [8000, 1000],
        [25000, 2500],
        [160000, 8000],
    ];

    public static function shouldAtomizeBacklog(
        int $backlogTokens,
        int $headroom,
        int $ctxSize
    ): bool {
        if ($backlogTokens <= 0) {
            return false;
        }

        $threshold = self::backlogThresholdForContext($ctxSize);
        $safetyHeadroom = self::safetyHeadroomForContext($ctxSize);

        if ($backlogTokens >= $threshold) {
            return true;
        }

        // Safety override: near the wall, reclaim whatever backlog exists.
        if ($headroom >= 0 && $headroom < $safetyHeadroom) {
            return true;
        }

        return false;
    }

    /** Backlog threshold (tokens) for a given context size, piecewise-linear. */
    public static function backlogThresholdForContext(int $ctxSize): int
    {
        return self::interpolate($ctxSize, self::BACKLOG_ANCHORS);
    }

    /** Safety headroom (tokens) for a given context size, piecewise-linear. */
    public static function safetyHeadroomForContext(int $ctxSize): int
    {
        return self::interpolate($ctxSize, self::SAFETY_HEADROOM_ANCHORS);
    }

    /**
     * Piecewise-linear interpolation over ascending [x, y] anchor pairs.
     * Clamps flat below the first anchor and above the last.
     */
    public static function interpolate(int $x, array $anchors): int
    {
        $x = max(0, $x);
        $first = $anchors[0];
        if ($x <= $first[0]) {
            return $first[1];
        }
        $last = $anchors[count($anchors) - 1];
        if ($x >= $last[0]) {
            return $last[1];
        }
        for ($i = 0, $n = count($anchors) - 1; $i < $n; $i++) {
            [$x0, $y0] = $anchors[$i];
            [$x1, $y1] = $anchors[$i + 1];
            if ($x >= $x0 && $x <= $x1) {
                $t = ($x - $x0) / ($x1 - $x0);
                return (int) round($y0 + $t * ($y1 - $y0));
            }
        }
        return $last[1];
    }

    /** Exponential moving average step. */
    public static function ema(float $previous, float $observed, float $alpha): float
    {
        return $previous + $alpha * ($observed - $previous);
    }
}
