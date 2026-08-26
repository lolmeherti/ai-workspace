<?php

declare(strict_types=1);

/*
 * LONG-SESSION RUN (live, E4B @ 16k). Repeated searches + normal chat.
 *
 * Drives the REAL ChatManager::process() over a mixed search/chat sequence and
 * tracks, per turn: prompt size, raw backlog (un-atomized evidence tokens),
 * atomic size (tokens of injected atoms), atomizer count/time, maintenance
 * stalls (consolidation_start->done), and answer quality.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomization-longsession-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

const CONDENSER_MARKER = 'The following text comes from external web sources';

final class LS_TimingAgent extends AgentManager
{
    public int $condenserCalls = 0;
    public array $condenserMs = [];

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
    {
        $sys = $messages[0]['content'] ?? '';
        $isCond = is_string($sys) && str_contains($sys, CONDENSER_MARKER);
        $t0 = microtime(true);
        $out = parent::chat($messages, $stream, $streamCallback, $temperature);
        if ($isCond) {
            $this->condenserCalls++;
            $this->condenserMs[] = (int) round((microtime(true) - $t0) * 1000);
        }
        return $out;
    }
}

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new LS_TimingAgent();
$cm = new ChatManager($db, $agent);

$ctxSize = (int) Config::get('LLM_CTX_SIZE', 16384);
$thr = \App\Agents\AtomizationPolicy::backlogThresholdForContext($ctxSize);

$db->insert('chat_sessions', ['title' => 'atomization-longsession-probe', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

function backlog(Database $db, int $sessionId): int
{
    $r = $db->query(
        "SELECT COALESCE(SUM(token_estimate),0) b FROM chat_history
         WHERE session_id=? AND message_type='data_fetching' AND active_context=1 AND atomic_context IS NULL",
        [$sessionId]
    );
    return (int) $r[0]['b'];
}

function atomicSize(Database $db, int $sessionId): int
{
    $rows = $db->query(
        "SELECT atomic_context FROM chat_history
         WHERE session_id=? AND message_type='data_fetching' AND active_context=1 AND atomic_context IS NOT NULL",
        [$sessionId]
    );
    $chars = 0;
    foreach ($rows as $r) {
        $claims = json_decode((string) ($r['atomic_context'] ?? ''), true) ?: [];
        foreach ($claims as $c) {
            $sid = (string) ($c['source_id'] ?? '');
            $claim = trim((string) ($c['claim'] ?? ''));
            if ($sid !== '' && $claim !== '') {
                $chars += mb_strlen("[{$sid}] {$claim}") + 1;
            }
        }
    }
    return (int) ceil($chars / 4);
}

function nRows(Database $db, int $sessionId): int
{
    $r = $db->query(
        "SELECT COUNT(*) c FROM chat_history WHERE session_id=? AND message_type='data_fetching' AND active_context=1",
        [$sessionId]
    );
    return (int) $r[0]['c'];
}

echo "LONG-SESSION RUN (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . " | ctx: {$ctxSize} | backlog threshold: {$thr}\n";
echo str_repeat('=', 96) . "\n";

$turns = [
    ['type' => 'search', 'q' => 'What is the capital of France?'],
    ['type' => 'search', 'q' => 'Who wrote the novel 1984?'],
    ['type' => 'chat',   'q' => 'Thanks. What was the first thing I asked you?'],
    ['type' => 'search', 'q' => 'What is the tallest mountain on Earth?'],
    ['type' => 'search', 'q' => 'When was the Eiffel Tower built?'],
    ['type' => 'chat',   'q' => 'Can you summarize everything we have found so far?'],
    ['type' => 'search', 'q' => 'What is the approximate population of Japan?'],
    ['type' => 'chat',   'q' => 'OK, thanks, that is all.'],
];

printf("%-3s %-6s %-38s | %-7s %-7s %-6s %-6s %-7s %-8s | %-6s\n",
    '#', 'type', 'query', 'prompt', 'backlog', 'atom', 'rows', 'atomizer', 'maintain', 'ttft');

$results = [];
foreach ($turns as $i => $turn) {
    $beforeBacklog = backlog($db, $sessionId);
    $beforeCond = $agent->condenserCalls;
    $beforeCondMs = count($agent->condenserMs);

    $t0 = microtime(true);
    $firstTokenMs = null;
    $maintStart = null;
    $maintEnd = null;
    $doneMs = null;
    $finalAnswer = '';
    $toolUsed = false;
    $citations = 0;

    $result = $cm->process($sessionId, $turn['q'], null, null, function (string $event, array $data = []) use (&$firstTokenMs, &$maintStart, &$maintEnd, &$doneMs, &$toolUsed, $t0): void {
        $now = (int) round((microtime(true) - $t0) * 1000);
        if ($event === 'consolidation_start') $maintStart = $now;
        elseif ($event === 'consolidation_done') $maintEnd = $now;
        elseif ($event === 'token' && $firstTokenMs === null) $firstTokenMs = $now;
        elseif ($event === 'done') {
            $doneMs = $now;
        } elseif ($event === 'tool_start') $toolUsed = true;
    });

    $afterBacklog = backlog($db, $sessionId);
    $afterAtomic = atomicSize($db, $sessionId);
    $rows = nRows($db, $sessionId);
    $condDelta = $agent->condenserCalls - $beforeCond;
    $condMs = array_slice($agent->condenserMs, $beforeCondMs);
    $condStr = $condDelta > 0 ? $condDelta . 'c/' . round(array_sum($condMs) / $condDelta) . 'ms' : '-';
    $maintStr = ($maintStart !== null && $maintEnd !== null) ? ($maintEnd - $maintStart) . 'ms' : '-';

    $prompt = $agent->lastUsage['prompt_tokens'] ?? null;
    if ($result['status'] === 'success') {
        // final answer is in history (last assistant row)
        $rows2 = $db->query("SELECT message FROM chat_history WHERE session_id=? AND role='assistant' ORDER BY id DESC LIMIT 1", [$sessionId]);
        $finalAnswer = $rows2[0]['message'] ?? '';
        $citations = preg_match_all('/\[S\d+\]/', $finalAnswer);
    }

    printf("%-3d %-6s %-38s | %-7s %-7d %-6d %-6d %-7s %-8s | %-6s\n",
        $i + 1, $turn['type'], substr($turn['q'], 0, 38),
        $prompt === null ? '-' : (string) $prompt,
        $afterBacklog, $afterAtomic, $rows,
        $condStr, $maintStr,
        $firstTokenMs === null ? '-' : $firstTokenMs . 'ms'
    );

    $results[] = [
        'turn' => $i + 1, 'type' => $turn['type'], 'query' => $turn['q'],
        'tool_used' => $toolUsed, 'prompt_tokens' => $prompt,
        'backlog_before' => $beforeBacklog, 'backlog_after' => $afterBacklog,
        'atomic_size' => $afterAtomic, 'rows' => $rows,
        'condenser_calls' => $condDelta, 'condenser_ms' => $condMs,
        'maintenance_ms' => ($maintStart !== null && $maintEnd !== null) ? $maintEnd - $maintStart : 0,
        'ttft_ms' => $firstTokenMs, 'final_ms' => $doneMs,
        'citations' => $citations, 'answer' => $finalAnswer,
    ];
}

echo str_repeat('=', 96) . "\n";
echo "PER-TURN ANSWERS (quality snapshot)\n";
echo str_repeat('=', 96) . "\n";
foreach ($results as $r) {
    $ans = preg_replace('/\s+/', ' ', $r['answer']);
    printf(
        "#%d [%s] tool=%s citations=%d | %s\n",
        $r['turn'], $r['type'], $r['tool_used'] ? 'Y' : 'N', $r['citations'],
        mb_substr($ans, 0, 160)
    );
}

echo "\nFINAL STATE:\n";
echo '  backlog (un-atomized) : ' . backlog($db, $sessionId) . " tokens\n";
echo '  atomic (injected)      : ' . atomicSize($db, $sessionId) . " tokens\n";
echo '  active evidence rows   : ' . nRows($db, $sessionId) . "\n";
echo '  total condenser calls  : ' . $agent->condenserCalls . "\n";
echo '  condenser latency      : ' . ($agent->condenserMs ? 'min=' . min($agent->condenserMs) . 'ms max=' . max($agent->condenserMs) . 'ms mean=' . (int) round(array_sum($agent->condenserMs) / count($agent->condenserMs)) . 'ms' : 'n/a') . "\n";

$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
echo "\ndone\n";
