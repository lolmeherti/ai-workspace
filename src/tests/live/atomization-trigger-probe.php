<?php

declare(strict_types=1);

/*
 * DEFERRED-ATOMIZATION TRIGGER + MAINTENANCE-DELAY PROBE (live, E4B).
 *
 * Drives the REAL ChatManager::process() end-to-end across three phases to
 * observe the actual trigger behavior, per-atomizer latency, total maintenance
 * delay, and answer latency (submit -> first token -> final):
 *
 *   Phase 1 — seed 3 un-atomized rows (2000/1800/1500 => backlog 5300 > thr 4706
 *             @ ctx 16384). Expect: fires (reason=backlog), atomizes 1 row
 *             (partial reclamation, largest-first), then a clean answer.
 *   Phase 2 — follow-up turn. Expect: does NOT fire (backlog now below threshold).
 *   Phase 3 — seed 2 more rows (backlog ~7365). Expect: fires again, atomizes 2 rows.
 *
 * A TimingAgent subclass records every LLM call (type, ms, tokens, server
 * timings) so condenser (atomizer) calls are separated from the chat inference.
 * The stream callback timestamps SSE events to give submit->TTFT->final.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomization-trigger-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Search\WebChunk;

Config::load(dirname(__DIR__, 2));

const CONDENSER_MARKER = 'The following text comes from external web sources';

final class TimingAgent extends AgentManager
{
    public array $calls = [];

    private function record(string $type, float $ms, array $messages): void
    {
        $u = $this->lastUsage ?? [];
        $t = $this->lastTimings ?? [];
        $this->calls[] = [
            'type' => $type,
            'ms' => (int) round($ms),
            'prompt_tokens' => $u['prompt_tokens'] ?? null,
            'completion_tokens' => $u['completion_tokens'] ?? null,
            'prompt_ms' => $t['prompt_ms'] ?? null,
            'predicted_ms' => $t['predicted_ms'] ?? null,
            'predicted_n' => $t['predicted_n'] ?? null,
        ];
    }

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null, ?string $reasoningEffort = null): string
    {
        $sys = $messages[0]['content'] ?? '';
        $type = (is_string($sys) && str_contains($sys, CONDENSER_MARKER)) ? 'condenser' : 'chat';
        $t0 = microtime(true);
        $out = parent::chat($messages, $stream, $streamCallback, $temperature);
        $this->record($type, (microtime(true) - $t0) * 1000, $messages);
        return $out;
    }

    public function chatToolCapable(array $messages, array $tools, string $toolChoice, callable $streamCallback = null, ?float $temperature = null): array
    {
        $t0 = microtime(true);
        $out = parent::chatToolCapable($messages, $tools, $toolChoice, $streamCallback, $temperature);
        $this->record('firstpass', (microtime(true) - $t0) * 1000, $messages);
        return $out;
    }
}

// ---- fixtures ----
function mkChunk(string $src, string $cid, string $text, int $pos): WebChunk
{
    return new WebChunk(
        $src, $cid, 'https://example.com/' . $cid, 'https://example.com/' . $cid,
        'Fixture', 'example.com', null, null, '2026-06-15T00:00:00Z', [], 'entity',
        $text, $pos, null, null, null, null
    );
}

function fillerPool(): array
{
    return [
        'The regular season saw the team post a league-best net rating while rotating eleven players through the lineup.',
        'Advanced analytics showed an improved defensive scheme that held opponents below their season scoring average.',
        'Coaching staff credited a revamped training program and film study routine for the late-season improvement.',
        'Fans filled the arena to capacity for each home game, setting a franchise attendance record for the calendar year.',
        'Local media praised the front office for a series of low-cost free-agent signings that added depth to the bench.',
        'The team travel schedule included three separate west-coast road trips with a combined fourteen games away.',
        'Injury reports remained mostly clean through the postseason, with only minor day-to-day designations.',
        'Broadcast ratings climbed double digits year over year, driven by a pair of nationally televised rivalry games.',
        'Merchandise sales set a single-month record following the playoff run, led by replica jerseys of the rookie starter.',
        'Community outreach events drew thousands of participants across the metro area during the off-season.',
    ];
}

function buildChunks(int $targetChars, string $seedFact): array
{
    $pool = fillerPool();
    $n = count($pool);
    $chunks = [];
    $chunks[] = mkChunk('S1', 'S1-C1', $seedFact, 1);
    $used = strlen($seedFact);
    $cursor = 0;
    $filler = '';
    while (strlen($filler) < ($targetChars - $used)) {
        $filler .= $pool[$cursor % $n] . ' ';
        $cursor++;
    }
    $chunks[] = mkChunk('S1', 'S1-C2', $filler, 2);
    return $chunks;
}

function seedRow(Database $db, int $sessionId, int $tokenEstimate, string $seedFact): int
{
    $chunks = buildChunks($tokenEstimate * 4, $seedFact);
    $db->insert('chat_history', [
        'session_id' => $sessionId,
        'role' => 'system',
        'message' => $seedFact . ' ' . implode(' ', array_map(fn($c) => $c->text, $chunks)),
        'message_type' => 'data_fetching',
        'tool_name' => 'search_web',
        'search_query' => 'Who won the 2026 NBA Finals?',
        'active_context' => 1,
        'atomic_context' => null,
        'selected_chunks' => json_encode($chunks, JSON_UNESCAPED_UNICODE),
        'token_estimate' => $tokenEstimate,
    ]);
    return (int) $db->getConnection()->lastInsertId();
}

function backlogOf(Database $db, int $sessionId): int
{
    $rows = $db->query(
        "SELECT COALESCE(SUM(token_estimate),0) AS b FROM chat_history
         WHERE session_id = ? AND message_type='data_fetching' AND active_context=1 AND atomic_context IS NULL",
        [$sessionId]
    );
    return (int) ($rows[0]['b'] ?? 0);
}

function atomizedCount(Database $db, int $sessionId): int
{
    $rows = $db->query(
        "SELECT COUNT(*) AS c FROM chat_history WHERE session_id = ? AND message_type='data_fetching' AND atomic_context IS NOT NULL",
        [$sessionId]
    );
    return (int) ($rows[0]['c'] ?? 0);
}

// ---- main ----
$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new TimingAgent();
$cm = new ChatManager($db, $agent);

$ctxSize = (int) Config::get('LLM_CTX_SIZE', 16384);
$thr = \App\Agents\AtomizationPolicy::backlogThresholdForContext($ctxSize);
$safe = \App\Agents\AtomizationPolicy::safetyHeadroomForContext($ctxSize);

$db->insert('chat_sessions', ['title' => 'atomization-trigger-probe', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

echo "DEFERRED-ATOMIZATION TRIGGER PROBE (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . " | ctx: {$ctxSize}\n";
echo "threshold: {$thr} | safety headroom: {$safe}\n";
echo str_repeat('=', 70) . "\n\n";

function runTurn(Database $db, ChatManager $cm, TimingAgent $agent, int $sessionId, string $query, string $phase): array
{
    $beforeBacklog = backlogOf($db, $sessionId);
    $beforeAtomized = atomizedCount($db, $sessionId);

    $events = [];
    $t0 = microtime(true);
    $firstTokenMs = null;
    $maintenanceStartMs = null;
    $maintenanceEndMs = null;
    $generatingMs = null;
    $doneMs = null;

    $result = $cm->process($sessionId, $query, null, null, function (string $event, array $data = []) use (&$events, &$firstTokenMs, &$maintenanceStartMs, &$maintenanceEndMs, &$generatingMs, &$doneMs, $t0): void {
        $nowMs = (int) round((microtime(true) - $t0) * 1000);
        $events[] = ['event' => $event, 'ms' => $nowMs, 'data' => $data];
        if ($event === 'consolidation_start') {
            $maintenanceStartMs = $nowMs;
        } elseif ($event === 'consolidation_done') {
            $maintenanceEndMs = $nowMs;
        } elseif ($event === 'generating') {
            $generatingMs = $nowMs;
        } elseif ($event === 'token' && $firstTokenMs === null) {
            $firstTokenMs = $nowMs;
        } elseif ($event === 'done') {
            $doneMs = $nowMs;
        }
    });

    $afterBacklog = backlogOf($db, $sessionId);
    $afterAtomized = atomizedCount($db, $sessionId);

    // condenser calls that happened during this turn = calls since last snapshot
    $condenserCalls = array_values(array_filter($agent->calls, fn($c) => $c['type'] === 'condenser'));
    $lastCondenser = $condenserCalls ? end($condenserCalls) : null;
    $firstpassCalls = array_values(array_filter($agent->calls, fn($c) => $c['type'] === 'firstpass'));
    $lastFirstpass = $firstpassCalls ? end($firstpassCalls) : null;

    echo "[{$phase}] query=\"{$query}\"\n";
    echo "  backlog before : {$beforeBacklog} tokens (threshold {$thr})\n";
    echo "  trigger reason : " . ($beforeBacklog >= $thr ? 'backlog' : (($beforeBacklog > 0 && $beforeBacklog < $safe) ? 'safety' : 'none')) . "\n";
    echo "  rows atomized  : " . ($afterAtomized - $beforeAtomized) . " (persisted total {$afterAtomized})\n";
    echo "  backlog after  : {$afterBacklog} tokens\n";
    echo "  reclaimed      : " . ($beforeBacklog - $afterBacklog) . " tokens\n";
    if ($lastCondenser !== null) {
        echo "  atomizer call  : {$lastCondenser['ms']} ms (in={$lastCondenser['prompt_tokens']} out={$lastCondenser['completion_tokens']}, server prompt_ms={$lastCondenser['prompt_ms']} predicted_ms={$lastCondenser['predicted_ms']})\n";
    }
    if ($maintenanceStartMs !== null) {
        echo "  maintenance    : {$maintenanceStartMs} -> {$maintenanceEndMs} ms (total " . (($maintenanceEndMs ?? 0) - $maintenanceStartMs) . " ms)\n";
    } else {
        echo "  maintenance    : none (no atomization this turn)\n";
    }
    if ($lastFirstpass !== null) {
        echo "  chat inference : {$lastFirstpass['ms']} ms\n";
    }
    echo "  submit->TTFT   : " . ($firstTokenMs !== null ? $firstTokenMs . ' ms' : 'n/a') . "\n";
    echo "  submit->final  : " . ($doneMs !== null ? $doneMs . ' ms' : 'n/a') . "\n";
    echo "  status         : " . ($result['status'] ?? '?') . "\n\n";

    return [
        'before_backlog' => $beforeBacklog,
        'after_backlog' => $afterBacklog,
        'rows_atomized' => $afterAtomized - $beforeAtomized,
        'reclaimed' => $beforeBacklog - $afterBacklog,
        'maintenance_ms' => ($maintenanceStartMs !== null && $maintenanceEndMs !== null) ? $maintenanceEndMs - $maintenanceStartMs : 0,
        'ttft_ms' => $firstTokenMs,
        'final_ms' => $doneMs,
    ];
}

// Phase 1: seed 3 rows, trigger fires once.
seedRow($db, $sessionId, 2000, 'The 2026 NBA Finals was won by the New York Knicks, who defeated the San Antonio Spurs four games to one.');
seedRow($db, $sessionId, 1800, 'Jalen Brunson was named Finals MVP after averaging 28.4 points per game.');
seedRow($db, $sessionId, 1500, 'The Knicks won the clinching Game 5 by a score of 112-104 on June 14, 2026.');

$r1 = runTurn($db, $cm, $agent, $sessionId, 'OK, understood.', 'PHASE 1 (fire)');

// Phase 2: follow-up, backlog below threshold -> no fire.
$r2 = runTurn($db, $cm, $agent, $sessionId, 'Thanks, got it.', 'PHASE 2 (skip)');

// Phase 3: seed 2 more rows -> backlog re-crosses threshold -> fire (2 rows).
seedRow($db, $sessionId, 2000, 'The Knicks championship parade was held on June 18, 2026 in Manhattan.');
seedRow($db, $sessionId, 2000, 'This was the Knicks first NBA title since 1973.');
$r3 = runTurn($db, $cm, $agent, $sessionId, 'Anything else to note?', 'PHASE 3 (re-fire)');

// ---- summary ----
echo str_repeat('=', 70) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 70) . "\n";
$condCalls = array_values(array_filter($agent->calls, fn($c) => $c['type'] === 'condenser'));
echo 'total LLM calls: ' . count($agent->calls)
    . ' (condenser=' . count($condCalls) . ', firstpass=' . count(array_filter($agent->calls, fn($c) => $c['type'] === 'firstpass')) . ")\n";
if ($condCalls) {
    $ms = array_column($condCalls, 'ms');
    echo 'atomizer call latency: min=' . min($ms) . 'ms max=' . max($ms) . 'ms mean=' . (int) round(array_sum($ms) / count($ms)) . "ms\n";
    echo 'atomizer server: prompt_ms=' . implode('/', array_map(fn($c) => (string) $c['prompt_ms'], $condCalls))
        . ' predicted_ms=' . implode('/', array_map(fn($c) => (string) $c['predicted_ms'], $condCalls)) . "\n";
}
printf(
    "maintenance delay (consolidation_start->done): P1=%dms P2=%dms P3=%dms\n",
    $r1['maintenance_ms'], $r2['maintenance_ms'], $r3['maintenance_ms']
);
printf(
    "submit->TTFT: P1=%dms P2=%dms P3=%dms | submit->final: P1=%dms P2=%dms P3=%dms\n",
    $r1['ttft_ms'], $r2['ttft_ms'], $r3['ttft_ms'],
    $r1['final_ms'], $r2['final_ms'], $r3['final_ms']
);
printf(
    "reclaimed: P1=%d P2=%d P3=%d tokens | rows atomized: P1=%d P2=%d P3=%d\n",
    $r1['reclaimed'], $r2['reclaimed'], $r3['reclaimed'],
    $r1['rows_atomized'], $r2['rows_atomized'], $r3['rows_atomized']
);

// Cleanup.
$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);

echo "\ndone\n";
