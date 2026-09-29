<?php
/**
 * End-to-end KV-cache measurement through the REAL production pipeline.
 *
 * Drives ChatManager::process() with a large seeded conversation (~28k real
 * prompt tokens), then prints the per-call cache metrics (cache_n / prompt_n /
 * prompt_ms) for the firstpass and the answer pass.
 *
 * NOTE: this must run INSIDE the app container, where ./src is mounted at
 * /var/www/html. To run:
 *   cp benchmarks/cache-probe.php src/_dbg_cache.php
 *   wsl.exe -d localsy-docker-backend docker exec -e XDEBUG_MODE=off ai_php_web php /var/www/html/_dbg_cache.php
 *   rm src/_dbg_cache.php
 *
 * The win to look for: the answer pass (last 'answer' call) cache_n should
 * reach ~ the firstpass prompt_n (reusing system + tools + conversation +
 * request), with prompt_n only ~ the fresh evidence tail. Before the KV-cache
 * fix this answer-pass cache_n was 0 (tools dropped => Qwen re-rendered the
 * system prompt without the tools block => full bust).
 */
require_once __DIR__ . '/../src/vendor/autoload.php';

use App\Config;
use App\Database;
use App\AgentManager;
use App\ChatManager;
use App\Logger;

Config::load(__DIR__ . '/../src');
$db = new Database();
Logger::setDatabase($db);
$agent = new AgentManager();
$chat = new ChatManager($db, $agent);

$db->executeStatement("INSERT INTO chat_sessions (title) VALUES (?)", ['cache-perf-' . time()]);
$sid = (int) $db->getConnection()->lastInsertId();
echo "session_id={$sid}\n";

$para = "The quick brown fox jumps over the lazy dog near the riverbank while the sun sets slowly over the hills. ";
$block = str_repeat($para, 400); // ~32KB ≈ 8k tokens
$seed = [
    ['user', "Here is a large reference document. Note its contents.\n\n" . $block],
    ['assistant', "Noted, I have the first document."],
    ['user', "Continuing with a second large section:\n\n" . $block],
    ['assistant', "Got it, continuing."],
    ['user', "And a third section:\n\n" . $block],
    ['assistant', "All three sections noted."],
];
foreach ($seed as [$role, $msg]) {
    $db->insert('chat_history', [
        'session_id' => $sid,
        'role' => $role,
        'message' => $msg,
        'token_estimate' => (int) (mb_strlen($msg) / 4),
    ]);
}
$seedTokens = (int) ($db->query(
    "SELECT COALESCE(SUM(token_estimate),0) s FROM chat_history WHERE session_id=?",
    [$sid]
)[0]['s'] ?? 0);
echo "seeded conversation tokens (estimate): {$seedTokens}\n";

$emit = function (string $event, array $data = []) {};
$t0 = microtime(true);
$result = $chat->process($sid, "What's on my agenda for the next few days?", null, null, null, $emit);
$elapsed = microtime(true) - $t0;
echo "turn elapsed: " . round($elapsed, 2) . "s  status: " . ($result['status'] ?? 'ok') . "\n\n";

foreach ($agent->callLog as $i => $c) {
    printf(
        "call[%d] purpose=%s cache_n=%d prompt_n=%d prompt_ms=%.0fms pred_n=%d pred_ms=%.0fms\n",
        $i,
        $c['purpose'] ?? '?',
        $c['cache_n'] ?? 0,
        $c['prompt_n'] ?? 0,
        $c['prompt_ms'] ?? 0,
        $c['pred_n'] ?? 0,
        $c['pred_ms'] ?? 0
    );
}

$db->executeStatement("DELETE FROM chat_history WHERE session_id = ?", [$sid]);
$db->executeStatement("DELETE FROM chat_sessions WHERE id = ?", [$sid]);
echo "\n(cleaned up session {$sid})\n";
