<?php

declare(strict_types=1);

/*
 * Regression check for the two failures that killed a stream mid-turn and surfaced in the UI as
 * "Connection ended before completion. Review the conversation before sending again.":
 *
 *   1. SQLSTATE 22001 — a tool query longer than chat_history.search_query (VARCHAR(255)) threw
 *      on insert.
 *   2. SQLSTATE 1452 — a chat_history insert whose parent session row was missing.
 *
 * Writes into a scratch session and removes everything it created.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/search-query-width-check.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Repositories\ChatSessionRepository;

Config::load(dirname(__DIR__, 2));
$db = new Database();
(new \App\Database\Schema($db))->initTables();

$repo = new ChatSessionRepository($db);
$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [OK]   {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "1. column width\n";
$cols = $db->query("SHOW COLUMNS FROM chat_history LIKE 'search_query'");
$type = strtolower((string) ($cols[0]['Type'] ?? ''));
check('search_query accepts long text (not VARCHAR(255))', strpos($type, 'text') !== false, "type={$type}");

echo "\n2. the exact overflow that threw\n";
$sessionId = $repo->create('scratch: search_query width check');
$longQuery = str_repeat('what did we decide about the engine restart path ', 30); // ~1500 chars
$threw = null;
try {
    $db->insert('chat_history', [
        'session_id'     => $sessionId,
        'role'           => 'system',
        'message'        => '(scratch)',
        'message_type'   => 'data_fetching',
        'tool_name'      => 'search_local',
        'search_query'   => $longQuery,
        'token_estimate' => 0,
    ]);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
check('a ' . strlen($longQuery) . '-char tool query inserts cleanly', $threw === null, (string) $threw);

$stored = $db->query("SELECT search_query FROM chat_history WHERE session_id = :sid ORDER BY id DESC LIMIT 1", [':sid' => $sessionId]);
check('it round-trips without truncation', ($stored[0]['search_query'] ?? '') === $longQuery, 'stored ' . strlen((string) ($stored[0]['search_query'] ?? '')) . ' chars');

echo "\n3. session guard used by the briefing stream\n";
$repo->delete($sessionId);
$repo->ensureExists($sessionId);
check('ensureExists recreates a deleted session', $repo->getById($sessionId) !== null);
$threw = null;
try {
    $db->insert('chat_history', [
        'session_id'     => $sessionId,
        'role'           => 'system',
        'message'        => '(scratch, after delete)',
        'message_type'   => 'data_fetching',
        'tool_name'      => 'search_local',
        'search_query'   => 'short',
        'token_estimate' => 0,
    ]);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
check('a history row inserts after the session was deleted and re-ensured', $threw === null, (string) $threw);

$db->query("DELETE FROM chat_history WHERE session_id = :sid", [':sid' => $sessionId]);
$repo->delete($sessionId);
check('scratch data removed', $repo->getById($sessionId) === null);

echo "\n" . ($fail === 0 ? "ALL CHECKS PASSED" : "FAILURES: {$fail}") . " ({$pass} passed, {$fail} failed)\n";
