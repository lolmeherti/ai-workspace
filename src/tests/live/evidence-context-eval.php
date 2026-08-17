<?php

declare(strict_types=1);

/*
 * Evidence-based context — session-wide retention evaluation (Phase 8).
 *
 * DETERMINISTIC INFRASTRUCTURE HARNESS — no web, no LLM, no live inference.
 *
 * Purpose: isolate whether Localsy's session-context infrastructure retains and
 * renders established facts correctly. Every fixture seeds controlled
 * chat_history / evidence rows directly, then drives the REAL components:
 *
 *   PromptAssemblyService  (buildMessagesArray / estimatePromptTokens /
 *                           extractVisibleSourceIds)
 *   CitationValidator      (sanitize / hallucination check / extract)
 *   ContextCondenser       (commitCondensation — summary supplied, never calls the LLM)
 *   SourceSequence         (session-global source IDs from persisted source_maps)
 *
 * Real web search is deliberately OUT: ranking changes, page churn, scraping and
 * network failures are orthogonal to retention and would only add nondeterminism.
 *
 * Conceptual split:
 *   1) REQUIRED INFRASTRUCTURE PASSES — must pass for Phase 8 to be green.
 *   2) BASELINE / KNOWN-GAP OBSERVATIONS — Phase 9 (atomization, speculation
 *      filtering) and deferred V2 (file-content promotion, 4096 profile floor)
 *      recorded for visibility, NOT required passes.
 *   3) MODEL-USE LAYER (manual, separate) — known rendered evidence + a question
 *      requiring it, against the live model, to split infrastructure-limited from
 *      model-limited failure. Not part of this script.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/evidence-context-eval.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agents\ContextCondenser;
use App\Config;
use App\Database;
use App\Search\CitationValidator;
use App\Search\SourceSequence;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

// Deterministic overrides — do not let .env drift change the measurement.
$_ENV['CHAT_ROLLING_WINDOW_LIMIT'] = '15';
$_ENV['LLM_EVIDENCE_TOOL_ROLE'] = '0';

$db = new Database();
$db->initTables();

$uploadDir = sys_get_temp_dir() . '/evidence_eval_' . uniqid() . '/';
mkdir($uploadDir, 0777, true);

$prompt = new PromptAssemblyService($db, $uploadDir, fn(string $t): int => strlen($t));
$validator = new CitationValidator();

$pass = 0;
$fail = 0;
$failures = [];
$baselineTotal = 0;
$baselineDiverged = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail, $failures;
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
    }
}

function checkEq(string $label, mixed $expected, mixed $actual): void
{
    global $pass, $fail, $failures;
    $ok = $expected === $actual;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        printf("        expected: %s\n        actual:   %s\n", var_export($expected, true), var_export($actual, true));
        $fail++;
        $failures[] = $label;
    } else {
        $pass++;
    }
}

function baseline(string $label, bool $matchesCurrentBehavior): void
{
    global $baselineTotal, $baselineDiverged;
    $baselineTotal++;
    if ($matchesCurrentBehavior) {
        printf("  [BASELINE] %s\n", $label);
    } else {
        printf("  [BASELINE-DIVERGED] %s\n", $label);
        $baselineDiverged++;
    }
}

function renderedText(array $messages): string
{
    $parts = [];
    foreach ($messages as $m) {
        $c = $m['content'] ?? '';
        if (is_string($c)) {
            $parts[] = $c;
        } elseif (is_array($c)) {
            foreach ($c as $part) {
                if (isset($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
        }
    }
    return implode("\n", $parts);
}

function occurrences(array $messages, string $needle): int
{
    return substr_count(renderedText($messages), $needle);
}

function conversation(int $n, string $prefix = 'conv'): array
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'role' => ($i % 2 === 0 ? 'user' : 'assistant'),
            'message' => "{$prefix} {$i}",
            'message_type' => 'text',
        ];
    }
    return $rows;
}

function seedSession(Database $db): int
{
    $db->insert('chat_sessions', ['title' => 'evidence-eval', 'context_tokens' => 0]);
    return (int)$db->getConnection()->lastInsertId();
}

function seedRow(
    Database $db,
    int $sessionId,
    string $role,
    string $message,
    string $type,
    int $active = 1,
    ?string $sourceMap = null
): void {
    $db->insert('chat_history', [
        'session_id' => $sessionId,
        'role' => $role,
        'message' => $message,
        'message_type' => $type,
        'active_context' => $active,
        'source_map' => $sourceMap,
    ]);
}

function cleanupSession(Database $db, int $sessionId): void
{
    $db->query("DELETE FROM chat_sessions WHERE id = ?", [$sessionId]);
}

$sys = 'SYSTEM';

// ===================================================================
// SECTION 1 — REQUIRED INFRASTRUCTURE PASSES
// ===================================================================
echo "\n============================================================\n";
echo "SECTION 1 — REQUIRED INFRASTRUCTURE PASSES\n";
echo "============================================================\n";

// -------------------------------------------------------------------
// F1 — controlled web evidence survives the rolling window
// -------------------------------------------------------------------
echo "\n=== F1: controlled evidence survives the rolling window ===\n";
$h = [['role' => 'system', 'message' => 'FACT_MARKER_ALPHA = 12345 <source id="S1">https://example/a</source>', 'message_type' => 'data_fetching']];
$h = array_merge($h, conversation(20));
$m = $prompt->buildMessagesArray($sys, $h);
checkEq('FACT_MARKER_ALPHA present exactly once after 20 messages', 1, occurrences($m, 'FACT_MARKER_ALPHA'));
check('evidence guard lists [S1]', str_contains($m[0]['content'] ?? '', '[S1]'));

// -------------------------------------------------------------------
// F2 — multi-search citations: disjoint IDs + CitationValidator
// -------------------------------------------------------------------
echo "\n=== F2: multi-search citations (S1,S2 + S3,S4) ===\n";
$h2 = [
    ['role' => 'system', 'message' => 'SEARCH_A_RESULT <source id="S1">u1</source><source id="S2">u2</source>', 'message_type' => 'data_fetching'],
    ['role' => 'system', 'message' => 'SEARCH_B_RESULT <source id="S3">u3</source><source id="S4">u4</source>', 'message_type' => 'data_fetching'],
    ['role' => 'user', 'message' => 'combine', 'message_type' => 'text'],
];
$visible = $prompt->extractVisibleSourceIds($h2);
checkEq('visible source union is S1..S4', ['S1', 'S2', 'S3', 'S4'], $visible);

check('valid citation [S2] not hallucinated', !$validator->hasHallucinatedCitations('Fact [S2].', $visible));
check('valid multi citation [S1, S4] not hallucinated', !$validator->hasHallucinatedCitations('Facts [S1, S4].', $visible));
check('non-visible [S9] flagged hallucinated', $validator->hasHallucinatedCitations('Fact [S9].', $visible));

$sanitized = $validator->sanitizeCitations('Alpha [S2] and beta [S9].', $visible);
check('sanitize strips visible and hallucinated markers', !str_contains($sanitized, '[S'));
check('sanitize preserves prose', str_contains($sanitized, 'Alpha') && str_contains($sanitized, 'beta'));
checkEq('extractCitations pulls cited IDs in order', ['S2', 'S3'], $validator->extractCitations('See [S2] and [S3].'));

$m2 = $prompt->buildMessagesArray($sys, $h2);
check('all four source markers present exactly once in evidence',
    occurrences($m2, '<source id="S1">') === 1 && occurrences($m2, '<source id="S2">') === 1 &&
    occurrences($m2, '<source id="S3">') === 1 && occurrences($m2, '<source id="S4">') === 1);
check('guard lists all four IDs',
    str_contains($m2[0]['content'] ?? '', '[S1]') && str_contains($m2[0]['content'] ?? '', '[S2]') &&
    str_contains($m2[0]['content'] ?? '', '[S3]') && str_contains($m2[0]['content'] ?? '', '[S4]'));

$s2 = seedSession($db);
try {
    seedRow($db, $s2, 'system', 'x', 'data_fetching', 1, json_encode(['S1' => ['url' => 'a'], 'S2' => ['url' => 'b']]));
    checkEq('nextSourceSeq after search A (S1,S2) -> 3', 3, SourceSequence::nextSourceSeq($db, $s2));
    seedRow($db, $s2, 'system', 'x', 'data_fetching', 1, json_encode(['S3' => ['url' => 'c'], 'S4' => ['url' => 'd']]));
    checkEq('nextSourceSeq after search B (S3,S4) -> 5 (no collision)', 5, SourceSequence::nextSourceSeq($db, $s2));
} catch (\Throwable $e) {
    check('F2 source-sequence exception: ' . $e->getMessage(), false);
} finally {
    cleanupSession($db, $s2);
}

// -------------------------------------------------------------------
// F3 — retrieved memory survives the rolling window
// -------------------------------------------------------------------
echo "\n=== F3: retrieved memory survives the rolling window ===\n";
$h3 = array_merge(
    [['role' => 'system', 'message' => 'MEMORY_MARKER_BRAVO = 98765', 'message_type' => 'data_fetching']],
    conversation(18)
);
checkEq('MEMORY_MARKER_BRAVO present exactly once', 1, occurrences($prompt->buildMessagesArray($sys, $h3), 'MEMORY_MARKER_BRAVO'));

// -------------------------------------------------------------------
// F4a — attached-file fact while its [File:] message is in-window (supported)
// -------------------------------------------------------------------
echo "\n=== F4a: attached-file fact injected while in-window ===\n";
file_put_contents($uploadDir . 'doc1.txt', 'FILE_MARKER_CHARLIE');
$h4a = [['role' => 'user', 'message' => 'what is in [File: doc1]?', 'message_type' => 'text']];
check('FILE_MARKER_CHARLIE injected via [File:] expansion', occurrences($prompt->buildMessagesArray($sys, $h4a), 'FILE_MARKER_CHARLIE') >= 1);

// -------------------------------------------------------------------
// F7 — no duplication of evidence
// -------------------------------------------------------------------
echo "\n=== F7: no duplication of evidence ===\n";
$h7 = [
    ['role' => 'system', 'message' => 'ONCE_MARKER', 'message_type' => 'data_fetching'],
    ['role' => 'user', 'message' => 'hi', 'message_type' => 'text'],
    ['role' => 'assistant', 'message' => 'hello', 'message_type' => 'text'],
];
checkEq('data_fetching injected exactly once (no doubling)', 1, occurrences($prompt->buildMessagesArray($sys, $h7), 'ONCE_MARKER'));

// -------------------------------------------------------------------
// F8 — evidence intact after condensation (active + evicted)
// -------------------------------------------------------------------
echo "\n=== F8: evidence intact after condensation ===\n";
$s8 = seedSession($db);
try {
    seedRow($db, $s8, 'user', 'search the web for X', 'text');
    seedRow($db, $s8, 'system', 'ACTIVE_MARKER', 'data_fetching', 1);
    seedRow($db, $s8, 'assistant', 'here is X', 'text');
    seedRow($db, $s8, 'user', 'now search Y', 'text');
    seedRow($db, $s8, 'system', 'EVICTED_MARKER', 'data_fetching', 0);
    seedRow($db, $s8, 'assistant', 'here is Y', 'text');

    (new ContextCondenser(new \App\AgentManager()))->commitCondensation($db, $s8, 'THE_SUMMARY', [], true);

    $h8 = $db->selectSafe('chat_history', ['session_id' => $s8]);
    $m8 = $prompt->buildMessagesArray($sys, $h8);
    check('active evidence survives condensation and stays in prompt', occurrences($m8, 'ACTIVE_MARKER') === 1);
    check('evicted evidence survives condensation but stays out of prompt', occurrences($m8, 'EVICTED_MARKER') === 0);

    $evictedRow = array_values(array_filter($h8, fn($r) => $r['message'] === 'EVICTED_MARKER'));
    checkEq('evicted row persisted with active_context=0', 0, (int)($evictedRow[0]['active_context'] ?? -1));
} catch (\Throwable $e) {
    check('F8 condensation exception: ' . $e->getMessage(), false);
} finally {
    cleanupSession($db, $s8);
}

// -------------------------------------------------------------------
// F9 — eviction / restore round-trip
// -------------------------------------------------------------------
echo "\n=== F9: eviction / restore round-trip ===\n";
$h9 = [['role' => 'system', 'message' => 'EVICT_MARKER', 'message_type' => 'data_fetching', 'active_context' => 1]];
checkEq('present while active', 1, occurrences($prompt->buildMessagesArray($sys, $h9), 'EVICT_MARKER'));
$h9[0]['active_context'] = 0;
checkEq('absent when evicted', 0, occurrences($prompt->buildMessagesArray($sys, $h9), 'EVICT_MARKER'));
$h9[0]['active_context'] = 1;
checkEq('present again after restore', 1, occurrences($prompt->buildMessagesArray($sys, $h9), 'EVICT_MARKER'));

// -------------------------------------------------------------------
// F10 — model switch reconstruction without KV
// -------------------------------------------------------------------
echo "\n=== F10: model switch / no KV ===\n";
$h10 = array_merge(
    [['role' => 'system', 'message' => 'KV_MARKER', 'message_type' => 'data_fetching']],
    conversation(20)
);
$m10a = $prompt->buildMessagesArray($sys, $h10);
$m10b = $prompt->buildMessagesArray($sys, $h10);
check('fact present on first assembly', occurrences($m10a, 'KV_MARKER') === 1);
check('fact present on a second, stateless assembly', occurrences($m10b, 'KV_MARKER') === 1);

// -------------------------------------------------------------------
// F11a — preflight refusal: 8192 overflow resolved by eviction
// -------------------------------------------------------------------
echo "\n=== F11a: preflight refusal + eviction resolves (8192) ===\n";
$h11 = [['role' => 'system', 'message' => str_repeat('E', 4000), 'message_type' => 'data_fetching', 'active_context' => 1]];
$b11 = $prompt->estimatePromptTokens('SYS', $h11, 'Q');
check('8192 profile overflows with heavy evidence', PromptAssemblyService::projectsOverflow($b11, 4096, 8192, 256));

$h11[0]['active_context'] = 0;
$b11b = $prompt->estimatePromptTokens('SYS', $h11, 'Q');
check('eviction resolves overflow at 8192', !PromptAssemblyService::projectsOverflow($b11b, 4096, 8192, 256));

// ===================================================================
// SECTION 2 — BASELINE / KNOWN-GAP OBSERVATIONS (Phase 9 / deferred V2)
// ===================================================================
echo "\n============================================================\n";
echo "SECTION 2 — BASELINE / KNOWN-GAP OBSERVATIONS (Phase 9 / V2)\n";
echo "============================================================\n";

// -------------------------------------------------------------------
// G1 — full attached-file durability (V2: file-content promotion)
// -------------------------------------------------------------------
echo "\n=== G1: attached-file durability past the rolling window (V2) ===\n";
$h4b = array_merge(
    [['role' => 'user', 'message' => 'what is in [File: doc1]?', 'message_type' => 'text']],
    conversation(20)
);
baseline('file fact drops out once its [File:] message rolls out (deferred V2)',
    occurrences($prompt->buildMessagesArray($sys, $h4b), 'FILE_MARKER_CHARLIE') === 0);

// -------------------------------------------------------------------
// G2 — user correction survives only via prose summary (Phase 9 atomization)
// -------------------------------------------------------------------
echo "\n=== G2: user correction via prose summary (Phase 9) ===\n";
$s5 = seedSession($db);
try {
    seedRow($db, $s5, 'user', 'ORIG_CORRECTION_RAW: call me Alex not Alexander', 'text');
    seedRow($db, $s5, 'system', 'EVIDENCE_KEEP', 'data_fetching', 1);
    seedRow($db, $s5, 'assistant', 'ok Alex', 'text');
    seedRow($db, $s5, 'user', 'and what about the report', 'text');
    seedRow($db, $s5, 'assistant', 'the report is ready', 'text');

    (new ContextCondenser(new \App\AgentManager()))->commitCondensation(
        $db,
        $s5,
        'User correction retained: CORRECTION_SENTINEL call me Alex. Then we discussed the report.',
        [],
        true
    );

    $h5 = $db->selectSafe('chat_history', ['session_id' => $s5]);
    $m5 = $prompt->buildMessagesArray($sys, $h5);
    $survivesViaSummary = occurrences($m5, 'CORRECTION_SENTINEL') >= 1 && occurrences($m5, 'ORIG_CORRECTION_RAW') === 0;
    baseline('correction survives only if captured in the prose summary (atomization is Phase 9)', $survivesViaSummary);
} catch (\Throwable $e) {
    baseline('G2 condensation exception: ' . $e->getMessage(), false);
} finally {
    cleanupSession($db, $s5);
}

// -------------------------------------------------------------------
// G3 — assistant speculation is not canonized (Phase 9 speculation filtering)
// -------------------------------------------------------------------
echo "\n=== G3: assistant speculation not canonized (Phase 9) ===\n";
$s6 = seedSession($db);
try {
    seedRow($db, $s6, 'user', 'should I switch jobs?', 'text');
    seedRow($db, $s6, 'assistant', 'SPECULATION_SENTINEL maybe you secretly hate it', 'text');
    seedRow($db, $s6, 'user', 'tell me more', 'text');
    seedRow($db, $s6, 'assistant', 'it depends', 'text');

    (new ContextCondenser(new \App\AgentManager()))->commitCondensation($db, $s6, 'User asked about switching jobs.', [], true);

    $h6 = $db->selectSafe('chat_history', ['session_id' => $s6]);
    baseline('assistant speculation is condensed away, not promoted to fact',
        occurrences($prompt->buildMessagesArray($sys, $h6), 'SPECULATION_SENTINEL') === 0);
} catch (\Throwable $e) {
    baseline('G3 condensation exception: ' . $e->getMessage(), false);
} finally {
    cleanupSession($db, $s6);
}

// -------------------------------------------------------------------
// G4 — 4096 profile floor (documented; output reserve alone fills window)
// -------------------------------------------------------------------
echo "\n=== G4: 4096 profile floor (documented) ===\n";
baseline('4096 profile refuses even after eviction (output reserve + safety fill the window)',
    PromptAssemblyService::projectsOverflow($b11b, 4096, 4096, 256));

// ===================================================================
// Summary
// ===================================================================
echo "\n" . str_repeat('=', 60) . "\n";
printf("REQUIRED INFRASTRUCTURE: %d passed, %d failed, %d total\n", $pass, $fail, $pass + $fail);
printf("BASELINE / KNOWN-GAP:     %d observed, %d diverged\n", $baselineTotal, $baselineDiverged);

if (!empty($failures)) {
    echo "\nREQUIRED FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nSOME REQUIRED FIXTURES FAILED\n";
} else {
    echo "ALL REQUIRED FIXTURES PASSED\n";
}

if ($baselineDiverged > 0) {
    echo "\nNOTE: {$baselineDiverged} baseline observation(s) diverged from expected current behavior.\n";
}

echo "\nMODEL-USE LAYER (manual, live inference — run separately):\n";
echo "  Preload known rendered evidence, ask a question that requires it, and\n";
echo "  record whether the answer uses it. Fact absent from the rendered prompt\n";
echo "  = infrastructure failure; fact present but answer wrong = model-limited\n";
echo "  (effective-context) failure. This script does not make model calls.\n";

foreach (glob($uploadDir . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($uploadDir);

exit($fail === 0 ? 0 : 1);
