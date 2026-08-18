<?php

declare(strict_types=1);

/*
 * Deterministic trigger-behavior matrix for the deferred atomization policy.
 * No LLM, no DB — exercises the REAL AtomizationPolicy class so the numbers
 * match production exactly.
 *
 * Prints, for each context size:
 *   - backlog threshold + safety headroom (the piecewise-linear anchors);
 *   - a trigger table: for representative backlog/headroom states, whether
 *     atomization fires and WHICH signal fired it.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomization-trigger-matrix.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agents\AtomizationPolicy;

// Context sizes: the three policy anchors (8k/25k/160k) plus E4B-supported
// profile sizes so live runs can be mapped onto the curve.
$ctxSizes = [8192, 16384, 25000, 32768, 50000, 65000, 100000, 131072, 160000];

function reason(int $backlog, int $headroom, int $ctx): string
{
    $thr = AtomizationPolicy::backlogThresholdForContext($ctx);
    $safe = AtomizationPolicy::safetyHeadroomForContext($ctx);
    if ($backlog <= 0) return 'none (no backlog)';
    if ($backlog >= $thr) return 'backlog';
    if ($headroom >= 0 && $headroom < $safe) return 'safety-headroom';
    return 'no';
}

echo "DEFERRED ATOMIZATION — TRIGGER MATRIX (deterministic)\n";
echo str_repeat('=', 72) . "\n\n";

echo "Thresholds (piecewise-linear, real policy class):\n";
printf("%-8s %14s %16s\n", 'ctx', 'backlog_thr', 'safety_headroom');
foreach ($ctxSizes as $ctx) {
    printf(
        "%-8d %14d %16d\n",
        $ctx,
        AtomizationPolicy::backlogThresholdForContext($ctx),
        AtomizationPolicy::safetyHeadroomForContext($ctx)
    );
}
echo "\n";

// Trigger table: representative backlog/headroom states per anchor ctx size.
// backlog states = just below / just above the threshold; headroom states =
// above and below safety headroom.
foreach ([8192, 25000, 160000] as $ctx) {
    $thr = AtomizationPolicy::backlogThresholdForContext($ctx);
    $safe = AtomizationPolicy::safetyHeadroomForContext($ctx);

    echo "--- ctx = {$ctx} (threshold {$thr}, safety {$safe}) ---\n";
    printf("%-12s %-12s %-16s %s\n", 'backlog', 'headroom', 'fires', 'reason');

    // backlog-driven: headroom ample, backlog around threshold
    foreach ([$thr - 200, $thr, $thr + 200] as $bl) {
        $bl = max(0, $bl);
        $hr = $ctx; // ample headroom
        $f = AtomizationPolicy::shouldAtomizeBacklog($bl, $hr, $ctx);
        printf(
            "%-12d %-12d %-16s %s\n",
            $bl, $hr, $f ? 'YES' : 'no', reason($bl, $hr, $ctx)
        );
    }

    // safety-driven: backlog below threshold, headroom near/at/below safety
    foreach ([$safe + 300, $safe, max(0, $safe - 300)] as $hr) {
        $bl = max(0, (int)($thr * 0.5)); // half the threshold
        $f = AtomizationPolicy::shouldAtomizeBacklog($bl, $hr, $ctx);
        printf(
            "%-12d %-12d %-16s %s\n",
            $bl, $hr, $f ? 'YES' : 'no', reason($bl, $hr, $ctx)
        );
    }

    // zero backlog never fires
    $f = AtomizationPolicy::shouldAtomizeBacklog(0, 0, $ctx);
    printf("%-12d %-12d %-16s %s\n", 0, 0, $f ? 'YES' : 'no', reason(0, 0, $ctx));

    echo "\n";
}

// Partial-reclamation sanity: the loop re-evaluates after each row. Show how
// many ~1.3k rows must be atomized before the loop stops, given the anchors.
echo "PARTIAL RECLAMATION — rows atomized to satisfy the trigger\n";
echo str_repeat('=', 72) . "\n";
foreach ([8192, 25000, 160000] as $ctx) {
    $thr = AtomizationPolicy::backlogThresholdForContext($ctx);
    $safe = AtomizationPolicy::safetyHeadroomForContext($ctx);

    // Simulate a backlog of equal 1.3k rows (raw) that atomize to ~65 tokens.
    // Largest-first, stop when backlog < threshold AND headroom >= safety.
    $rowRaw = 1300;
    $rowAtom = 65;
    $rowReclaim = $rowRaw - $rowAtom;

    // Start with enough rows to exceed threshold by ~20%.
    $startBacklog = (int)($thr * 1.2);
    $nRows = (int) ceil($startBacklog / $rowRaw);

    $bl = $startBacklog;
    $hr = $ctx; // ample headroom to start
    $atomized = 0;
    $backlogNow = $bl;
    while (AtomizationPolicy::shouldAtomizeBacklog($backlogNow, $hr, $ctx)) {
        $backlogNow = max(0, $backlogNow - $rowReclaim);
        $hr += $rowReclaim;
        $atomized++;
        if ($atomized > $nRows + 5) break;
    }
    printf(
        "ctx %-7d start_backlog=%-6d (%d rows) -> atomized %d rows, backlog %d, headroom %d\n",
        $ctx, $startBacklog, $nRows, $atomized, $backlogNow, $hr
    );
}

echo "\ndone\n";
