<?php

declare(strict_types=1);

/*
 * PERFORMANCE METRICS E2E (live). Drives real turns through ChatManager and
 * verifies each assistant message now carries a perf_metrics payload:
 * total_ms, ttft_ms, and a per-call log with purpose + server timings.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/perf-metrics-e2e.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new AgentManager();
$cm = new ChatManager($db, $agent);

$db->insert('chat_sessions', ['title' => 'perf-metrics-e2e', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

echo "PERF-METRICS E2E (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . ' | ctx: ' . Config::get('LLM_CTX_SIZE', '?') . "\n";
echo str_repeat('=', 80) . "\n";

function runTurn(Database $db, ChatManager $cm, int $sessionId, string $query, string $label): void
{
    $events = [];
    $t0 = microtime(true);
    $firstTokenMs = null;
    $doneMs = null;
    $donePerf = null;

    $result = $cm->process($sessionId, $query, null, null, function (string $event, array $data = []) use (&$events, &$firstTokenMs, &$doneMs, &$donePerf, $t0): void {
        $now = (int) round((microtime(true) - $t0) * 1000);
        $events[] = $event;
        if ($event === 'token' && $firstTokenMs === null) $firstTokenMs = $now;
        if ($event === 'done') { $doneMs = $now; $donePerf = $data['perf_metrics'] ?? null; }
    });

    // Read back the persisted perf_metrics from the newest assistant row.
    $rows = $db->query("SELECT id, perf_metrics FROM chat_history WHERE session_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1", [$sessionId]);
    $row = $rows[0] ?? null;
    $persisted = $row && !empty($row['perf_metrics']) ? json_decode($row['perf_metrics'], true) : null;

    echo "\n--- {$label} ---\n";
    echo '  query          : ' . $query . "\n";
    echo '  status         : ' . ($result['status'] ?? '?') . "\n";
    echo '  events         : ' . implode(' -> ', array_values(array_unique($events))) . "\n";
    echo '  done.perf      : ' . ($donePerf !== null ? 'present' : 'MISSING') . "\n";
    echo '  persisted      : ' . ($persisted !== null ? 'present' : 'MISSING') . "\n";

    if ($persisted !== null) {
        echo '  total_ms       : ' . ($persisted['total_ms'] ?? '?') . "\n";
        echo '  ttft_ms        : ' . ($persisted['ttft_ms'] ?? 'null') . "\n";
        $calls = $persisted['calls'] ?? [];
        echo '  calls          : ' . count($calls) . "\n";
        foreach ($calls as $c) {
            printf(
                "    [%s] %dms  prefill=%dms(%d tok, %d cached)  think=%dms(%d tok)  text=%dms(%d tok)  pred_tps=%s\n",
                $c['purpose'] ?? '?',
                $c['elapsed_ms'] ?? 0,
                (int) round($c['prompt_ms'] ?? 0),
                $c['prompt_n'] ?? 0,
                $c['cache_n'] ?? 0,
                $c['reasoning_ms'] ?? 0,
                $c['reasoning_tok'] ?? 0,
                $c['content_ms'] ?? 0,
                $c['content_tok'] ?? 0,
                round($c['pred_tps'] ?? 0, 1),
            );
        }
    }
}

// Turn 1: normal chat (expect a single 'firstpass' call).
runTurn($db, $cm, $sessionId, 'Reply with exactly: OK', 'TURN 1 (normal)');

// Turn 2: tool turn — asks for web info (expect firstpass + answer + maybe condenser).
runTurn($db, $cm, $sessionId, 'Search the web for the capital of Australia and tell me the answer.', 'TURN 2 (tool)');

$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
echo "\ndone\n";
