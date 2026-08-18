<?php

declare(strict_types=1);

/*
 * Phase 3 — backing_chunks persistence round-trip (DETERMINISTIC, no LLM).
 *
 * Verifies the schema migration adds backing_chunks, and that the exact JSON
 * shape ChatManager writes (json_encode of WebChunk[] with JSON_UNESCAPED_UNICODE)
 * round-trips through the data_fetching row with source/chunk identity intact.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/backing-chunks-roundtrip.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Search\WebChunk;

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

echo "BACKING CHUNKS ROUND-TRIP (deterministic)\n\n";

$cols = $db->query("SHOW COLUMNS FROM chat_history LIKE 'backing_chunks'");
check('backing_chunks column present after initTables', !empty($cols));

$chunks = [
    new WebChunk(
        sourceId: 'S1',
        chunkId: 'S1-C1',
        url: 'https://example.com/finals',
        finalUrl: 'https://example.com/finals',
        title: '2026 NBA Finals',
        domain: 'example.com',
        publishedAt: null,
        updatedAt: null,
        fetchedAt: '2026-08-17T00:00:00+00:00',
        headingPath: ['Finals'],
        sectionType: 'entity',
        text: 'The Knicks won the 2026 NBA Finals in 5 games. Mit einem deutschen Umlaut: äöü.',
        position: 1,
    ),
    new WebChunk(
        sourceId: 'S1',
        chunkId: 'S1-C2',
        url: 'https://example.com/finals',
        finalUrl: 'https://example.com/finals',
        title: '2026 NBA Finals',
        domain: 'example.com',
        publishedAt: null,
        updatedAt: null,
        fetchedAt: '2026-08-17T00:00:00+00:00',
        headingPath: ['Finals', 'Game 5'],
        sectionType: 'entity',
        text: 'New York defeated San Antonio 4-1.',
        position: 2,
    ),
    new WebChunk(
        sourceId: 'S2',
        chunkId: 'S2-C1',
        url: 'https://example.com/game3',
        finalUrl: 'https://example.com/game3',
        title: 'Spurs 115-111 Knicks, Game 3',
        domain: 'example.com',
        publishedAt: null,
        updatedAt: null,
        fetchedAt: '2026-08-17T00:00:00+00:00',
        headingPath: [],
        sectionType: 'entity',
        text: 'Game 3 box score.',
        position: 1,
        score: 8,
    ),
];

$encoded = json_encode($chunks, JSON_UNESCAPED_UNICODE);
check('WebChunk[] json-encodes (public props)', is_string($encoded) && str_contains($encoded, '"chunkId":"S1-C1"'));
check('Unicode preserved without escaping', str_contains($encoded, 'äöü'));

$sourceMap = json_encode(['S1' => ['url' => 'https://example.com/finals', 'title' => '2026 NBA Finals', 'domain' => 'example.com'], 'S2' => ['url' => 'https://example.com/game3', 'title' => 'Game 3', 'domain' => 'example.com']]);

$db->insert('chat_sessions', ['title' => 'backing-roundtrip', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

$db->insert('chat_history', [
    'session_id' => $sessionId,
    'role' => 'system',
    'message' => '<source id="S1">...</source>',
    'message_type' => 'data_fetching',
    'tool_name' => 'search_web',
    'source_map' => $sourceMap,
    'backing_chunks' => $encoded,
]);

$rows = $db->query('SELECT backing_chunks, source_map FROM chat_history WHERE session_id = ? AND message_type = ?', [$sessionId, 'data_fetching']);
check('row inserted with backing_chunks', count($rows) === 1);

$decoded = json_decode($rows[0]['backing_chunks'], true);
check('backing_chunks decodes to array', is_array($decoded));
check('chunk count preserved', is_array($decoded) && count($decoded) === 3);
check('sourceId identity S1', ($decoded[0]['sourceId'] ?? '') === 'S1');
check('chunkId identity S1-C1', ($decoded[0]['chunkId'] ?? '') === 'S1-C1');
check('chunkId identity S2-C1', ($decoded[2]['chunkId'] ?? '') === 'S2-C1');
check('text identity preserved', ($decoded[0]['text'] ?? '') === 'The Knicks won the 2026 NBA Finals in 5 games. Mit einem deutschen Umlaut: äöü.');
check('title preserved for provenance', ($decoded[0]['title'] ?? '') === '2026 NBA Finals');
check('score nullable field preserved', ($decoded[2]['score'] ?? null) === 8);

$sm = json_decode($rows[0]['source_map'], true);
check('source_map round-trips alongside backing', ($sm['S1']['domain'] ?? '') === 'example.com');

$db->query('DELETE FROM chat_history WHERE session_id = ?', [$sessionId]);
$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);

echo "\n" . str_repeat('=', 55) . "\n";
printf("ROUND-TRIP: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
