<?php

declare(strict_types=1);

/*
 * Post-answer evidence consolidation — live end-to-end (one real web turn),
 * with per-phase timing. Records the exact inference ordering, SSE event
 * ordering, and the six timing metrics:
 *   time to tool decision, tool/search time, time until final answer starts,
 *   time until final answer finishes, consolidation duration, total SSE-open.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/post-answer-consolidation-e2e.php [query]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;

Config::load(dirname(__DIR__, 2));

final class CountingAgent extends AgentManager
{
    /** @var string[] ordered list of 'chatToolCapable' | 'chat' | 'chatWithTools' */
    public array $calls = [];

    /** @var array<int, array{type:string,start:float,end:float}> */
    public array $callTimings = [];

    public function chatToolCapable(array $messages, array $tools, string $toolChoice, callable $streamCallback = null, ?float $temperature = null): array
    {
        $start = microtime(true);
        $this->calls[] = 'chatToolCapable';
        $r = parent::chatToolCapable($messages, $tools, $toolChoice, $streamCallback, $temperature);
        $this->callTimings[] = ['type' => 'chatToolCapable', 'start' => $start, 'end' => microtime(true)];
        return $r;
    }

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): string
    {
        $start = microtime(true);
        $this->calls[] = 'chat';
        $r = parent::chat($messages, $stream, $streamCallback, $temperature);
        $this->callTimings[] = ['type' => 'chat', 'start' => $start, 'end' => microtime(true)];
        return $r;
    }
}

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new CountingAgent();
$cm = new ChatManager($db, $agent);

$query = $argv[1] ?? 'who won the 2026 NBA Finals';

$db->insert('chat_sessions', ['title' => 'e2e-consolidation', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

$t0 = microtime(true);
$events = [];

$result = $cm->process($sessionId, $query, null, null, function (string $event, array $data = []) use (&$events, $t0): void {
    $events[] = [
        'seq' => count($events),
        'ms' => (int) round((microtime(true) - $t0) * 1000),
        'event' => $event,
        'data' => $data,
    ];
});

$doneMs = (int) round((microtime(true) - $t0) * 1000);

echo "POST-ANSWER CONSOLIDATION E2E (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . "\n";
echo "query: {$query}\n";
echo 'status: ' . ($result['status'] ?? '?') . "\n\n";

// 1. Inference order + per-inference timing.
echo "INFERENCE ORDER + DURATION:\n";
foreach ($agent->calls as $i => $c) {
    $t = $agent->callTimings[$i] ?? null;
    $ms = $t ? (int) round(($t['end'] - $t['start']) * 1000) : -1;
    echo '  ' . ($i + 1) . ". {$c}  ({$ms}ms)\n";
}
echo "\n";

// 2. Collapsed SSE event sequence.
echo "SSE EVENT SEQUENCE (key events):\n";
$answerTokens = 0;
$reasoningChunks = 0;
foreach ($events as $e) {
    $ev = $e['event'];
    if ($ev === 'token') { $answerTokens++; continue; }
    if ($ev === 'reasoning') { $reasoningChunks++; continue; }
    if ($ev === 'scraping_start' || $ev === 'scraping_done' || $ev === 'search_querying') { continue; }
    $extra = '';
    if ($ev === 'tool_start') { $extra = ' tool=' . ($e['data']['tool'] ?? '?'); }
    if ($ev === 'consolidation_start' || $ev === 'consolidation_done' || $ev === 'consolidation_error') { $extra = ' ' . json_encode($e['data']); }
    echo '  ' . str_pad((string) $e['seq'], 3, ' ', STR_PAD_LEFT) . ". +" . str_pad((string) $e['ms'], 6, ' ', STR_PAD_LEFT) . "ms  {$ev}{$extra}\n";
}
echo "  (answer token chunks: {$answerTokens}, reasoning chunks: {$reasoningChunks})\n\n";

// 3. Persistence.
$rows = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
$dataRows = array_values(array_filter($rows, fn($r) => ($r['message_type'] ?? '') === 'data_fetching'));
echo "PERSISTENCE:\n";
echo '  data_fetching rows: ' . count($dataRows) . "\n";
foreach ($dataRows as $r) {
    $atomic = $r['atomic_context'] ?? null;
    $preview = $atomic === null ? 'NULL' : json_encode(json_decode($atomic, true), JSON_UNESCAPED_UNICODE);
    echo "  row id={$r['id']} tool=" . ($r['tool_name'] ?? '-') . " atomic_context={$preview}\n";
}
echo "\n";

// 4. Timing metrics.
function eventMs(array $events, string $name, bool $first = true): ?int
{
    $hits = array_values(array_filter($events, fn($e) => $e['event'] === $name));
    if (empty($hits)) {
        return null;
    }
    return $first ? $hits[0]['ms'] : $hits[count($hits) - 1]['ms'];
}

function eventSeq(array $events, string $name, bool $first = true): ?int
{
    $hits = array_values(array_filter($events, fn($e) => $e['event'] === $name));
    if (empty($hits)) {
        return null;
    }
    return $first ? $hits[0]['seq'] : $hits[count($hits) - 1]['seq'];
}

$toolDecisionMs = isset($agent->callTimings[0]) ? (int) round(($agent->callTimings[0]['end'] - $t0) * 1000) : null;
$searchEndMs = eventMs($events, 'tool_done', false);
$answerStartMs = eventMs($events, 'token', true);
$answerEndMs = eventMs($events, 'token', false);
$consStartMs = eventMs($events, 'consolidation_start', true);
$consDoneMs = eventMs($events, 'consolidation_done', true);
$consInferenceMs = isset($agent->callTimings[2]) ? (int) round(($agent->callTimings[2]['end'] - $agent->callTimings[2]['start']) * 1000) : null;

echo "TIMING METRICS:\n";
echo '  time to tool decision        : ' . fmt($toolDecisionMs) . "\n";
echo '  tool/search time             : ' . (($toolDecisionMs !== null && $searchEndMs !== null) ? fmt($searchEndMs - $toolDecisionMs) : 'n/a') . "\n";
echo '  time until final answer starts: ' . fmt($answerStartMs) . "\n";
echo '  time until final answer finishes: ' . fmt($answerEndMs) . "\n";
echo '  answer stream duration       : ' . (($answerStartMs !== null && $answerEndMs !== null) ? fmt($answerEndMs - $answerStartMs) : 'n/a') . "\n";
echo '  consolidation duration       : ' . (($consStartMs !== null && $consDoneMs !== null) ? fmt($consDoneMs - $consStartMs) : 'n/a') . ' (inference #3: ' . fmt($consInferenceMs) . ")\n";
echo '  total SSE-open duration      : ' . fmt($doneMs) . "\n";
echo "\n";

function fmt(?int $ms): string
{
    return $ms === null ? 'n/a' : $ms . 'ms';
}

// 5. Assertions.
$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
}

$calls = $agent->calls;
check('inference #1 = tool selection (chatToolCapable)', ($calls[0] ?? '') === 'chatToolCapable');
check('inference #2 = answer (chat)', ($calls[1] ?? '') === 'chat');
check('inference #3 = consolidation (chat)', ($calls[2] ?? '') === 'chat');
check('exactly 3 inferences (no 4th)', count($calls) === 3);

$sToolStart = eventSeq($events, 'tool_start', true);
$sFirstToken = eventSeq($events, 'token', true);
$sConsStart = eventSeq($events, 'consolidation_start', true);
$sConsDone = eventSeq($events, 'consolidation_done', true);
$sDone = eventSeq($events, 'done', true);

check('tool selection before answer', $sToolStart !== null && $sFirstToken !== null && $sToolStart < $sFirstToken);
check('answer visible before consolidation_start', $sFirstToken !== null && $sConsStart !== null && $sConsStart > $sFirstToken);
check('consolidation_start before consolidation_done', $sConsStart !== null && $sConsDone !== null && $sConsDone > $sConsStart);
check('consolidation_done before done', $sConsDone !== null && $sDone !== null && $sDone > $sConsDone);
check('atomic_context persisted (non-null) on data_fetching row', !empty($dataRows) && ($dataRows[0]['atomic_context'] ?? null) !== null);

$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
