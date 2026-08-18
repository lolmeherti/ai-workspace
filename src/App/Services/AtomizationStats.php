<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AtomizationPolicy;
use App\Config;
use App\Database;

/**
 * Single-row persistence for the observed consolidation-cost EMA. The policy
 * needs a consolidation-latency estimate BEFORE running inference #3, but the
 * only honest source is the server-measured timings of past consolidations —
 * so this stores a one-row running average, seeded from config on first use and
 * updated after every consolidation that actually runs.
 */
final class AtomizationStats
{
    public function __construct(private Database $db)
    {
    }

    public function consolidationMsEma(): float
    {
        $rows = $this->db->query('SELECT consolidation_ms_ema FROM atomization_stats WHERE id = 1');
        if (empty($rows) || $rows[0]['consolidation_ms_ema'] === null) {
            return (float) Config::get('ATOMIZATION_CONSOLIDATION_MS_SEED', 4500.0);
        }
        return (float) $rows[0]['consolidation_ms_ema'];
    }

    public function recordConsolidation(float $observedMs): void
    {
        if ($observedMs <= 0.0) {
            return;
        }

        $alpha = (float) Config::get('ATOMIZATION_EMA_ALPHA', 0.3);
        $next = AtomizationPolicy::ema($this->consolidationMsEma(), $observedMs, $alpha);

        $this->db->executeStatement(
            'INSERT INTO atomization_stats (id, consolidation_ms_ema, samples, updated_at) '
            . 'VALUES (1, :ema, 1, NOW()) '
            . 'ON DUPLICATE KEY UPDATE consolidation_ms_ema = :ema2, samples = samples + 1, updated_at = NOW()',
            [':ema' => $next, ':ema2' => $next]
        );
    }
}
