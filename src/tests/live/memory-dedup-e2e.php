<?php

declare(strict_types=1);

/*
 * Memory read-path dedup E2E (DETERMINISTIC, no LLM).
 *
 * Exercises the real MemorySelector + SearchMemoriesTool against self-seeded
 * memories (unique sentinels — never touches existing user memories) to verify:
 *
 *   1. ID-based dedup: several synonym sub-queries matching the same memory
 *      collapse to a single copy (no repeated rows);
 *   2. matching semantics are unchanged: broad fuzzy recall is preserved — a
 *      generic token ("number") still matches every memory containing it, and
 *      legitimate synonyms still retrieve the right memory.
 *
 * NOTE: retrieval precision (stoplisting generic tokens to reduce noisy
 * matches) is intentionally NOT tuned here — it is recorded as future debt.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/memory-dedup-e2e.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\AgentManager;
use App\Services\Tools\SearchMemoriesTool;

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
$agent = new AgentManager();
$tool = new SearchMemoriesTool($db, $agent);
$noop = static function (): void {};

$INS = '0000000000'; // insurance fixture sentinel
$DEB = '1111111111'; // debit card fixture sentinel
$MOB = '2222222222'; // mobile fixture sentinel

// Distinct, non-overlapping prefixes so the fixtures can never collide with
// real user memories; "number" is deliberately shared to exercise fuzzy recall.
$fixtures = [
    "alphaqzx insurance number / alphaqzx social security number / alphaqzx ecard: {$INS}",
    "bravoqzx debit card number: {$DEB}",
    "charlieqzx mobile number: {$MOB}",
];

$ids = [];
foreach ($fixtures as $text) {
    $db->insert('memories', ['memory_text' => $text]);
    $ids[] = (int) $db->getConnection()->lastInsertId();
}

try {
    echo "MEMORY READ-PATH DEDUP (deterministic)\n\n";

    // ── case 1: ID-based dedup across synonym sub-queries ──
    echo "--- case 1: synonym sub-queries collapse to one copy ---\n";
    $res = $tool->execute(
        ['query' => 'alphaqzx insurance number, alphaqzx social security number, alphaqzx ecard'],
        0, [], $noop, '{}'
    );
    check('insurance memory appears exactly once', substr_count($res, $INS) === 1);
    check('debit memory appears at most once', substr_count($res, $DEB) <= 1);
    check('mobile memory appears at most once', substr_count($res, $MOB) <= 1);

    // ── case 2: fuzzy recall is preserved (no token filtering) ──
    echo "--- case 2: generic token still matches broadly ---\n";
    $res = $tool->execute(['query' => 'number'], 0, [], $noop, '{}');
    check('"number" matches insurance memory', str_contains($res, $INS));
    check('"number" matches debit memory', str_contains($res, $DEB));
    check('"number" matches mobile memory', str_contains($res, $MOB));

    // ── case 3: legitimate synonym retrieval still works ──
    echo "--- case 3: legitimate synonym retrieval ---\n";
    $res = $tool->execute(['query' => 'alphaqzx social security number'], 0, [], $noop, '{}');
    check('"social security number" retrieves insurance memory', str_contains($res, $INS));
} finally {
    foreach ($ids as $id) {
        $db->executeStatement('DELETE FROM memories WHERE id = :id', [':id' => $id]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
