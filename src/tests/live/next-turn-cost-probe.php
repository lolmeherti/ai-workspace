<?php

declare(strict_types=1);

/*
 * Raw -> atomic next-turn cost probe (live).
 *
 * Does one real web turn (search + answer + post-answer consolidation), then
 * measures what the NEXT turn costs with atoms injected vs with the raw
 * evidence kept. Reports:
 *   - raw evidence tokens vs atom tokens (TokenCounter);
 *   - next-turn prompt_tokens (llama.cpp usage) in both configs;
 *   - next-turn TTFT (time to first chunk, a prefill proxy) in both configs;
 *   - a warm re-run of the raw config to estimate KV/prefix-reuse value.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/next-turn-cost-probe.php [query]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Search\TokenCounter;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$agent = new AgentManager();
$cm = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, '/tmp');
$counter = new TokenCounter();

$query = $argv[1] ?? 'who won the 2026 NBA Finals';
$followUp = 'Thanks — give me a one-sentence summary.';

// 1. Real web turn.
$db->insert('chat_sessions', ['title' => 'next-turn-cost-probe', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

echo "RAW->ATOMIC NEXT-TURN COST PROBE (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', '?') . "\n";
echo "query: {$query}\n";

$result = $cm->process($sessionId, $query, null, null, function (string $event, array $data = []): void {});
echo 'web turn status: ' . ($result['status'] ?? '?') . "\n\n";

// 2. Extract raw message + atoms from the data_fetching row.
$rows = $db->selectSafe('chat_history', ['session_id' => $sessionId]);
$dataRows = array_values(array_filter($rows, fn($r) => ($r['message_type'] ?? '') === 'data_fetching'));
if (empty($dataRows)) {
    echo "ERROR: no data_fetching row produced (search may have returned nothing).\n";
    $db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
    exit(1);
}
$row = $dataRows[0];
$rowId = (int) $row['id'];
$rawMessage = (string) ($row['message'] ?? '');
$claims = json_decode((string) ($row['atomic_context'] ?? '[]'), true) ?: [];
$atomLines = implode("\n", array_map(fn($c) => "[{$c['source_id']}] {$c['claim']}", $claims));

$rawTokens = $counter->count($rawMessage);
$atomTokens = $counter->count($atomLines);

echo "EVIDENCE TOKEN SIZES (TokenCounter):\n";
echo "  raw message tokens : {$rawTokens}\n";
echo "  atom tokens        : {$atomTokens}\n";
echo '  shrink             : ' . ($rawTokens > 0 ? round((1 - $atomTokens / $rawTokens) * 100, 1) . '%' : 'n/a') . "\n\n";

// 3. Build the next-turn messages in both configs (identical except evidence block).
$systemPrompt = $pas->buildSystemPrompt($followUp);
$history = $db->selectSafe('chat_history', ['session_id' => $sessionId]);

$msgsAtoms = $pas->buildMessagesArray($systemPrompt, $history, [], []);
$msgsAtoms[] = ['role' => 'user', 'content' => $followUp];

$msgsRaw = $pas->buildMessagesArray($systemPrompt, $history, [], [$rowId]);
$msgsRaw[] = ['role' => 'user', 'content' => $followUp];

function measure(AgentManager $agent, array $messages): array
{
    $firstMs = null;
    $t0 = microtime(true);
    $agent->chat($messages, true, function (string $chunk, string $type) use (&$firstMs, $t0): void {
        if ($firstMs === null) {
            $firstMs = (int) round((microtime(true) - $t0) * 1000);
        }
    });
    $elapsed = (int) round((microtime(true) - $t0) * 1000);
    $usage = $agent->lastUsage ?? [];
    return [
        'ttft_ms' => $firstMs,
        'elapsed_ms' => $elapsed,
        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
        'completion_tokens' => $usage['completion_tokens'] ?? null,
    ];
}

// 4. Measure next turn: atoms (cold), then raw (cold), then raw again (warm).
$atoms = measure($agent, $msgsAtoms);
$rawCold = measure($agent, $msgsRaw);
$rawWarm = measure($agent, $msgsRaw);

echo "NEXT-TURN MEASUREMENTS (follow-up: \"{$followUp}\")\n";
echo '  atoms (cold) : prompt_tokens=' . fmtInt($atoms['prompt_tokens']) . '  ttft=' . fmtInt($atoms['ttft_ms']) . 'ms  total=' . fmtInt($atoms['elapsed_ms']) . "ms\n";
echo '  raw   (cold) : prompt_tokens=' . fmtInt($rawCold['prompt_tokens']) . '  ttft=' . fmtInt($rawCold['ttft_ms']) . 'ms  total=' . fmtInt($rawCold['elapsed_ms']) . "ms\n";
echo '  raw   (warm) : prompt_tokens=' . fmtInt($rawWarm['prompt_tokens']) . '  ttft=' . fmtInt($rawWarm['ttft_ms']) . 'ms  total=' . fmtInt($rawWarm['elapsed_ms']) . "ms\n\n";

function fmtInt($v): string
{
    return $v === null ? 'n/a' : (string) $v;
}

// 5. Comparison.
echo "COMPARISON:\n";
if ($atoms['prompt_tokens'] !== null && $rawCold['prompt_tokens'] !== null) {
    $promptSave = $rawCold['prompt_tokens'] - $atoms['prompt_tokens'];
    echo '  prompt shrink (atoms vs raw) : ' . $promptSave . ' tokens (' . round($promptSave / max(1, $rawCold['prompt_tokens']) * 100, 1) . "%)\n";
}
if ($atoms['ttft_ms'] !== null && $rawCold['ttft_ms'] !== null) {
    echo '  prefill/TTFT saving (cold)   : ' . ($rawCold['ttft_ms'] - $atoms['ttft_ms']) . "ms\n";
}
if ($rawCold['ttft_ms'] !== null && $rawWarm['ttft_ms'] !== null) {
    $kvValue = $rawCold['ttft_ms'] - $rawWarm['ttft_ms'];
    echo '  KV/prefix-reuse value (raw)  : ' . $kvValue . "ms (cold - warm)\n";
    if ($atoms['ttft_ms'] !== null) {
        echo '  atoms cold vs raw warm       : ' . ($atoms['ttft_ms'] - $rawWarm['ttft_ms']) . "ms (negative = atoms cold prefill is faster than raw warm prefill)\n";
    }
}

$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);

echo "\ndone\n";
