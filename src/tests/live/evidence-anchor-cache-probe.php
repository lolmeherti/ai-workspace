<?php

declare(strict_types=1);

/*
 * Evidence-anchor cache probe (live, read-only).
 *
 * D8 verification for .hermes/plans/cache-optimization.md. Builds two adjacent
 * turns of a real session through the real PromptAssemblyService, asserts that
 * turn N is a strict prefix of turn N+1, then replays both at the engine. The
 * engine reuses a prefix only when the new prompt extends the sequence it still
 * holds, so N-then-N+1 on a warm engine is the measurement; the reuse figure is
 * printed by the engine itself (see the `= N reused` lines in the engine log).
 *
 * Nothing is written to the database.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/evidence-anchor-cache-probe.php [session_id]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

$sessionId = (int) ($argv[1] ?? 0);
if ($sessionId <= 0) {
    fwrite(STDERR, "usage: evidence-anchor-cache-probe.php <session_id>\n");
    exit(1);
}

$db = new Database();
$pas = new PromptAssemblyService($db, '/tmp');

$rows = $db->selectSafe('chat_history', ['session_id' => $sessionId], 'id', false);
if (count($rows) < 3) {
    fwrite(STDERR, "session {$sessionId} has too few rows\n");
    exit(1);
}

// Turn N = the session as it stood before its last user row; turn N+1 = now.
$lastUserIdx = 0;
foreach ($rows as $i => $row) {
    if (($row['role'] ?? '') === 'user') {
        $lastUserIdx = $i;
    }
}
$turnN = array_slice($rows, 0, $lastUserIdx);
$turnN1 = $rows;

// Placeholder system prompt: this probe measures the arrangement of the turns,
// not the prompt's wording, and a constant keeps the two passes comparable.
$sys = 'SYS: evidence-anchor probe placeholder (arrangement is what is measured).';

$arrN = $pas->buildMessagesArray($sys, $turnN);
$arrN1 = $pas->buildMessagesArray($sys, $turnN1);

echo "evidence-anchor cache probe (live, read-only)\n";
echo "session {$sessionId}, {$lastUserIdx} rows before the last user turn\n";
echo 'turn N   messages: ' . count($arrN) . "\n";
echo 'turn N+1 messages: ' . count($arrN1) . "\n";

$prefix = count($arrN1) > count($arrN);
for ($i = 0; $prefix && $i < count($arrN); $i++) {
    $prefix = json_encode($arrN[$i]) === json_encode($arrN1[$i]);
}
echo 'turn N is a strict prefix of turn N+1: ' . ($prefix ? "YES\n" : "NO\n");

for ($i = 0; $i < min(count($arrN), count($arrN1)); $i++) {
    if (json_encode($arrN[$i]) !== json_encode($arrN1[$i])) {
        echo "first divergence at message {$i}:\n";
        echo '  N:   ' . substr(str_replace("\n", ' ', (string) $arrN[$i]['content']), 0, 100) . "\n";
        echo '  N+1: ' . substr(str_replace("\n", ' ', (string) $arrN1[$i]['content']), 0, 100) . "\n";
        break;
    }
}

// Arrangement, so a silent re-anchoring is visible at a glance.
foreach (['N' => $arrN, 'N+1' => $arrN1] as $label => $arr) {
    $shape = [];
    foreach ($arr as $m) {
        $len = is_array($m['content'] ?? null)
            ? strlen((string) json_encode($m['content']))
            : strlen((string) ($m['content'] ?? ''));
        $shape[] = ($m['role'] ?? '?') . ':' . $len;
    }
    echo "shape {$label}: " . implode(' ', $shape) . "\n";
}

$base = rtrim((string) Config::get('LLM_API_URL', ''), '/');
if ($base === '') {
    echo "\nno LLM_API_URL; engine replay skipped\n";
    exit(0);
}

// Prefer the engine's real model id when it advertises one.
$model = 'probe';
$ch = curl_init($base . '/models');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$modelsRaw = curl_exec($ch);
curl_close($ch);
$models = json_decode((string) $modelsRaw, true);
if (!empty($models['data'][0]['id'])) {
    $model = (string) $models['data'][0]['id'];
}

function replay(string $base, string $model, array $messages): array
{
    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 16,
            'stream' => false,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $t0 = microtime(true);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'ms' => (int) round((microtime(true) - $t0) * 1000),
        'usage' => json_decode((string) $out, true)['usage'] ?? null,
        'body' => substr((string) $out, 0, 240),
    ];
}

echo "\n--- engine replay ({$base}, model {$model}) ---\n";
foreach (['N' => $arrN, 'N+1' => $arrN1] as $label => $arr) {
    $res = replay($base, $model, $arr);
    echo $label . ': http ' . $res['code'] . ', ' . $res['ms'] . ' ms, usage ' . json_encode($res['usage']) . "\n";
    if ($res['code'] !== 200) {
        echo '  body: ' . $res['body'] . "\n";
    }
}

echo "\nReuse comes from the engine's own log line for each call:\n";
echo "  \"prompt <n> tokens = <reused> reused + <read> read\".\n";
echo "Expected after D1: N reuses little (cold by design), N+1 reuses N's whole\n";
echo "prompt and reads only its own new rows — instead of re-reading the evidence.\n";
