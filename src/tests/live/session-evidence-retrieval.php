<?php

declare(strict_types=1);

/*
 * Phase 5 — session evidence retrieval (DETERMINISTIC, no LLM).
 *
 * Exercises the real SearchSessionEvidenceTool against seeded data_fetching
 * rows and verifies the transient lifecycle:
 *
 *   1. exact cold detail found -> transient retrieval with S#-C# provenance +
 *      original [S#] source, and NO new durable Context Data row;
 *   2. the retrieved detail is not auto-injected on a follow-up turn (it is
 *      never persisted to message/atomic_context);
 *   3. evicted source -> not retrievable;
 *   4. restored source -> retrievable again;
 *   5. no useful local evidence -> result signals escalation to search_web;
 *   6. optional source filter;
 *   7. WebChunk::fromArray round-trips persisted chunks.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/session-evidence-retrieval.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Search\WebChunk;
use App\Services\Tools\SearchSessionEvidenceTool;

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

echo "SESSION EVIDENCE RETRIEVAL (deterministic)\n\n";

// ── Seed a session with one active data_fetching row carrying backing_chunks ──
$s1 = [
    new WebChunk('S1', 'S1-C1', 'https://example.com/finals', 'https://example.com/finals',
        'Basketball-Reference Finals', 'basketball-reference.com', null, null, '2026-08-17T00:00:00Z',
        ['Finals'], 'entity',
        'The 2026 NBA Finals was won by the New York Knicks, who defeated the San Antonio Spurs four games to one.',
        1),
    // Cold detail — the game list that was NOT atomized (the follow-up needs it).
    new WebChunk('S1', 'S1-C4', 'https://example.com/finals', 'https://example.com/finals',
        'Basketball-Reference Finals', 'basketball-reference.com', null, null, '2026-08-17T00:00:00Z',
        ['Finals', 'Schedule'], 'entity',
        'Finals schedule and scores: Game 1 NYK 108 SAS 95; Game 2 NYK 112 SAS 99; Game 3 SAS 115 NYK 111; Game 4 NYK 118 SAS 102; Game 5 NYK 112 SAS 104.',
        4),
];
$s2 = [
    new WebChunk('S2', 'S2-C1', 'https://example.com/game3', 'https://example.com/game3',
        'ESPN Game 3 recap', 'espn.com', null, null, '2026-08-17T00:00:00Z',
        [], 'entity',
        'SAN ANTONIO — The Spurs beat the Knicks 115-111 in Game 3 to cut the series deficit to 2-1.',
        1),
];

$atomicContext = json_encode([
    ['source_id' => 'S1', 'chunk_ids' => ['S1-C1'], 'claim' => 'The 2026 NBA Finals was won by the New York Knicks.'],
    ['source_id' => 'S2', 'chunk_ids' => ['S2-C1'], 'claim' => 'The Spurs beat the Knicks 115-111 in Game 3.'],
], JSON_UNESCAPED_UNICODE);

$db->insert('chat_sessions', ['title' => 'session-evidence-test', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

$db->insert('chat_history', [
    'session_id' => $sessionId,
    'role' => 'system',
    'message' => '<source id="S1">full evidence</source>',
    'message_type' => 'data_fetching',
    'tool_name' => 'search_web',
    'search_query' => 'who won the nba',
    'source_map' => json_encode(['S1' => ['url' => 'https://example.com/finals', 'title' => 'Finals', 'domain' => 'example.com']]),
    'backing_chunks' => json_encode(array_merge($s1, $s2), JSON_UNESCAPED_UNICODE),
    'atomic_context' => $atomicContext,
    'active_context' => 1,
]);

$tool = new SearchSessionEvidenceTool($db);
$noop = static function () {};

$rowCount = fn() => count($db->selectSafe('chat_history', ['session_id' => $sessionId, 'message_type' => 'data_fetching']));

// 7. WebChunk::fromArray round-trip.
$reconstructed = WebChunk::fromArray(json_decode(json_encode($s1[1]), true));
check('fromArray reconstructs sourceId/chunkId', $reconstructed->sourceId === 'S1' && $reconstructed->chunkId === 'S1-C4');
check('fromArray preserves text', $reconstructed->text === $s1[1]->text);

echo "\n--- case 1: exact cold detail found ---\n";
$before = $rowCount();
$res = $tool->execute(['query' => 'finals schedule and scores'], $sessionId, [], $noop, '{}');
$after = $rowCount();
check('cold detail retrieved', str_contains($res, 'Game 1 NYK 108 SAS 95'));
check('chunk provenance [S1-C4] present', str_contains($res, '[S1-C4]'));
check('no new Context Data row (transient)', $before === $after);
check('retrieved source resolves to original [S1]', $tool->getLastRetrievedSourceIds() === ['S1']);

echo "\n--- case 2: retrieved detail is not auto-injected on a follow-up turn ---\n";
$persisted = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
$leaked = false;
foreach ($persisted as $row) {
    if (str_contains((string)($row['message'] ?? ''), 'Game 1 NYK 108') || str_contains((string)($row['atomic_context'] ?? ''), 'Game 1 NYK 108')) {
        $leaked = true;
    }
}
check('retrieved chunk not persisted to message/atomic_context', !$leaked);

echo "\n--- case 3: evicted source is not retrievable ---\n";
$db->update('chat_history', ['active_context' => 0], ['session_id' => $sessionId, 'message_type' => 'data_fetching']);
$res = $tool->execute(['query' => 'finals schedule and scores'], $sessionId, [], $noop, '{}');
check('evicted source excluded', !str_contains($res, 'Game 1 NYK 108'));

echo "\n--- case 4: restored source is retrievable again ---\n";
$db->update('chat_history', ['active_context' => 1], ['session_id' => $sessionId, 'message_type' => 'data_fetching']);
$res = $tool->execute(['query' => 'finals schedule and scores'], $sessionId, [], $noop, '{}');
check('restored source retrievable', str_contains($res, 'Game 1 NYK 108 SAS 95'));

echo "\n--- case 5: no useful local evidence signals escalation ---\n";
$res = $tool->execute(['query' => 'lasagna recipe ingredients'], $sessionId, [], $noop, '{}');
check('escalation to search_web is signaled', str_contains($res, 'search_web') && str_contains($res, 'no retained evidence'));

echo "\n--- case 6: source filter ---\n";
$res = $tool->execute(['query' => 'finals schedule and scores', 'source_ids' => ['S2']], $sessionId, [], $noop, '{}');
check('source filter S2 excludes S1-C4', !str_contains($res, 'Game 1 NYK 108'));
$res = $tool->execute(['query' => 'finals schedule and scores', 'source_ids' => ['S1']], $sessionId, [], $noop, '{}');
check('source filter S1 returns S1-C4', str_contains($res, 'Game 1 NYK 108 SAS 95'));

// Cleanup.
$db->executeStatement('DELETE FROM chat_history WHERE session_id = :sid', [':sid' => $sessionId]);
$db->executeStatement('DELETE FROM chat_sessions WHERE id = :sid', [':sid' => $sessionId]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
