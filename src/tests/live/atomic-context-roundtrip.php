<?php

declare(strict_types=1);

/*
 * Phase 4 — atomic_context round-trip + injection (DETERMINISTIC, no LLM).
 *
 * Verifies:
 *   1. schema migration adds atomic_context;
 *   2. the atomic-claim JSON shape ChatManager writes round-trips;
 *   3. SourceCondenser::parseClaimsBatched rejects invalid + cross-source refs
 *      and derives source IDs (provenance guarantee);
 *   4. PromptAssemblyService injects compact [S#] HOT fact lines instead of the
 *      full message when atomic_context is present, and falls back to message;
 *   5. extractVisibleSourceIds prefers atomic_context source IDs.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomic-context-roundtrip.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Database;
use App\Search\SourceCondenser;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
}

$db = new Database();
$db->initTables();

echo "ATOMIC CONTEXT ROUND-TRIP (deterministic)\n\n";

// 1. Schema column.
$cols = $db->query("SHOW COLUMNS FROM chat_history LIKE 'atomic_context'");
check('atomic_context column present after initTables', !empty($cols));

// 2. JSON shape round-trip (what ChatManager writes).
$claims = [
    ['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'The 2026 NBA Finals was won by the New York Knicks.'],
    ['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'The series concluded in Game 5.'],
    ['source_id' => 'S2', 'chunk_ids' => ['S2-C1'], 'claim' => 'The Spurs beat the Knicks 115-111 in Game 3.'],
];
$json = json_encode($claims, JSON_UNESCAPED_UNICODE);
$decoded = json_decode($json, true);
check('atomic-claim JSON round-trips (count)', is_array($decoded) && count($decoded) === 3);
check('atomic-claim JSON preserves Unicode', str_contains($json, 'New York Knicks') && $decoded[2]['claim'] === 'The Spurs beat the Knicks 115-111 in Game 3.');
check('atomic-claim keys are source_id/chunk_ids/claim', isset($decoded[0]['source_id'], $decoded[0]['chunk_ids'], $decoded[0]['claim']));

// 3. parseClaimsBatched provenance (reflection — pure string parsing).
$condenser = new SourceCondenser(new AgentManager());
$rc = new ReflectionClass(SourceCondenser::class);
$parse = $rc->getMethod('parseClaimsBatched');
$parse->setAccessible(true);

$validSet = array_flip(['S1-C1', 'S1-C2', 'S2-C1', 'S3-C1']);

$raw = "- [S1-C1] Knicks won 4-1.\n" .
       "- [S1-C2] Series ended Game 5.\n" .
       "- [S2-C1] Spurs won Game 3 115-111.\n" .
       "- [S9-C9] Invented chunk ID.\n" .
       "- [S1-C1,S3-C1] Cross-source merge.\n" .
       "- [S1-C1,S1-C2] Multi-chunk same source.\n" .
       "- [S1-C1] Knicks won 4-1.\n";

$parsed = $parse->invoke($condenser, $raw, $validSet);
check('parseClaimsBatched drops invalid refs', count($parsed) === 4);
check('parseClaimsBatched drops cross-source merge', !in_array(['S1-C1', 'S3-C1'], array_column($parsed, 'chunk_ids'), true));
check('parseClaimsBatched keeps same-source multi-chunk', in_array(['S1-C1', 'S1-C2'], array_column($parsed, 'chunk_ids'), true));
check('parseClaimsBatched dedupes duplicate claim', array_count_values(array_column($parsed, 'claim'))['Knicks won 4-1.'] === 1);
check('parseClaimsBatched derives source_id', $parsed[0]['source_id'] === 'S1' && $parsed[2]['source_id'] === 'S2');

// 4. injectedEvidenceContent (reflection).
$svc = new PromptAssemblyService($db, '/tmp');
$rc2 = new ReflectionClass(PromptAssemblyService::class);
$render = $rc2->getMethod('injectedEvidenceContent');
$render->setAccessible(true);

$rowAtomic = [
    'atomic_context' => $json,
    'message' => '<source id="S1"><domain>x</domain></source><source id="S2"><domain>y</domain></source>',
];
$hot = $render->invoke($svc, $rowAtomic);
check('HOT render is compact [S#] fact lines (no raw JSON)', str_contains($hot, '[S1] The 2026 NBA Finals') && str_contains($hot, '[S2] The Spurs beat the Knicks') && !str_contains($hot, 'source_id'));
check('HOT render suppresses full message', !str_contains($hot, '<source'));

$rowFallback = ['atomic_context' => null, 'message' => 'full evidence text'];
check('falls back to message when no atomic_context', $render->invoke($svc, $rowFallback) === 'full evidence text');

$rowEmptyAtoms = ['atomic_context' => '[]', 'message' => 'full evidence text'];
check('falls back to message when atomic_context is empty array', $render->invoke($svc, $rowEmptyAtoms) === 'full evidence text');

// 5. extractVisibleSourceIds prefers atomic_context.
$history = [
    [
        'message_type' => 'data_fetching',
        'active_context' => 1,
        'atomic_context' => $json,
        'message' => '<source id="S3"><domain>z</domain></source>',
    ],
];
$ids = $svc->extractVisibleSourceIds($history);
check('extractVisibleSourceIds reads atomic source_ids only', $ids === ['S1', 'S2']);

$historyLegacy = [
    [
        'message_type' => 'data_fetching',
        'active_context' => 1,
        'atomic_context' => null,
        'message' => '<source id="S4"><domain>w</domain></source>',
    ],
];
$idsLegacy = $svc->extractVisibleSourceIds($historyLegacy);
check('extractVisibleSourceIds falls back to <source id> tags', $idsLegacy === ['S4']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
