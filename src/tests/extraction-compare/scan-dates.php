<?php

/**
 * Breadth check: run the chrome date probe over every saved capture and report
 * how many pages would gain a publication/update date that production has no
 * channel for today. Deterministic, no model calls.
 *
 * Usage: php scan-dates.php [--no-metadata]
 */

$dir = __DIR__ . '/runs';
$noMeta = in_array('--no-metadata', $argv, true);
$probe = __DIR__ . '/date-probe.php';

$rows = [];
foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $run) {
    $snap = basename($run);
    if (str_starts_with($snap, '_') || !is_file($run . '/page.html')) continue;
    $meta = json_decode((string) @file_get_contents($run . '/capture-meta.json'), true) ?: [];
    $cmd = 'php ' . escapeshellarg($probe) . ' --snapshot=' . escapeshellarg($snap) . ' --json'
        . ($noMeta ? ' --no-metadata' : '') . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $j = $out ? json_decode($out, true) : null;
    if (!is_array($j)) { $rows[] = [$snap, 'probe-failed', '', '', 0, (string) ($meta['url'] ?? '')]; continue; }
    $rows[] = [
        $snap,
        $j['publishedAt'] ?? null ?: '-',
        $j['updatedAt'] ?? null ?: '-',
        $j['published_signal'] ?? $j['updated_signal'] ?? 'none',
        count($j['candidates'] ?? []),
        (string) ($meta['url'] ?? ''),
    ];
}

$resolved = 0; $none = 0; $metaSignal = 0; $textSignal = 0;
foreach ($rows as $r) {
    if ($r[3] === 'probe-failed') continue;
    $has = ($r[1] !== '-' || $r[2] !== '-');
    if (!$has) { $none++; continue; }
    $resolved++;
    if (str_contains((string) $r[3], 'metadata') || str_contains((string) $r[3], 'time-element')) $metaSignal++;
    else $textSignal++;
}

printf("%-26s %-20s %-20s %-16s %s\n", 'snapshot', 'published', 'updated', 'signal', 'candidates');
foreach ($rows as $r) {
    printf("%-26s %-20s %-20s %-16s %d\n", $r[0], mb_substr((string) $r[1], 0, 19), mb_substr((string) $r[2], 0, 19), (string) $r[3], $r[4]);
}
printf("\ncaptures probed: %d | resolved a date: %d (metadata/markup signal %d, page text only %d) | nothing found: %d\n",
    count($rows), $resolved, $metaSignal, $textSignal, $none);
