<?php

declare(strict_types=1);

/*
 * ATOM PERSISTENCE PROBE (live, E4B). How long do atoms remain useful?
 *
 * Seeds ONE atomized evidence row (real SourceCondenser atoms), then asks the
 * same fact question at turns 1 / 3 / 5 with intervening distractor turns, and
 * checks fact retention + citation correctness each time. Atoms are re-injected
 * every turn (active_context=1), so this measures recall stability as the
 * conversation grows.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/atomization-persistence-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\SourceCondenser;
use App\Search\WebChunk;
use App\Services\PromptAssemblyService;

const PROBE_TEMP = 0.0;

Config::load(dirname(__DIR__, 2));

class P_NoopDb extends \App\Database
{
    public function __construct()
    {
    }

    public function query(string $sql, array $params = []): array
    {
        return [];
    }
}

function p_chunk(string $src, string $cid, string $text, int $pos): WebChunk
{
    return new WebChunk(
        $src, $cid, 'https://example.com/' . $cid, 'https://example.com/' . $cid,
        'Fixture', 'example.com', null, null, '2026-06-15T00:00:00Z', [], 'entity',
        $text, $pos, null, null, null, null
    );
}

function p_atoms(array $claims): array
{
    return [
        'role' => 'system', 'message' => '(raw omitted)', 'message_type' => 'data_fetching',
        'active_context' => 1, 'atomic_context' => json_encode($claims, JSON_UNESCAPED_UNICODE),
    ];
}

function p_turn(string $role, string $message): array
{
    return ['role' => $role, 'message' => $message, 'message_type' => 'text'];
}

function p_contains(string $h, string $n): bool
{
    return $n !== '' && mb_stripos($h, $n) !== false;
}

$pas = new PromptAssemblyService(new P_NoopDb(), sys_get_temp_dir(), fn(string $t): int => strlen($t));
$agent = new AgentManager();
$system = 'You are a helpful assistant. Be concise and answer the user\'s question.';

$model = (string) Config::get('LLM_MODEL_NAME', '?');
echo "ATOM PERSISTENCE PROBE (live)\n";
echo "model: {$model} | ctx: " . Config::get('LLM_CTX_SIZE', '?') . " | temp: " . PROBE_TEMP . "\n";
echo str_repeat('-', 70) . "\n";

// Build REAL atoms from a small fixture.
$chunks = [
    p_chunk('S1', 'S1-C1', 'The 2026 NBA Finals was won by the New York Knicks, who beat the San Antonio Spurs four games to one.', 1),
    p_chunk('S1', 'S1-C2', 'The Knicks won the clinching Game 5 by a score of 112-104 on June 14, 2026.', 2),
];
$condenser = new SourceCondenser($agent);
$claims = $condenser->condenseBatched($chunks, 'Who won the 2026 NBA Finals and what was the Game 5 score?');
echo "atoms (" . count($claims) . "):\n";
foreach ($claims as $c) {
    echo "  [{$c['source_id']}] {$c['claim']}\n";
}
echo str_repeat('-', 70) . "\n";

$history = [p_atoms($claims)];

function askq(AgentManager $agent, PromptAssemblyService $pas, string $system, array $history, string $q): string
{
    $h = array_merge($history, [p_turn('user', $q)]);
    $messages = $pas->buildMessagesArray($system, $h);
    try {
        return trim($agent->chat($messages, false, null, PROBE_TEMP));
    } catch (\Throwable $e) {
        return 'ERROR: ' . $e->getMessage();
    }
}

// Distractor turns + recall checks at turns 1 / 3 / 5.
$schedule = [
    ['t' => 1, 'q' => 'Who won the 2026 NBA Finals?', 'required' => ['Knicks']],
    ['t' => 2, 'q' => 'Thanks, just taking notes.', 'required' => []],
    ['t' => 3, 'q' => 'What was the Game 5 score?', 'required' => ['112-104']],
    ['t' => 4, 'q' => 'Understood, please continue.', 'required' => []],
    ['t' => 5, 'q' => 'Remind me again: who won the Finals and the Game 5 score?', 'required' => ['Knicks', '112-104']],
];

$pass = 0;
$checks = 0;
foreach ($schedule as $step) {
    $ans = askq($agent, $pas, $system, $history, $step['q']);
    $missing = array_values(array_filter($step['required'], fn($r) => !p_contains($ans, $r)));
    $cited = preg_match_all('/\[S\d+\]/', $ans);
    $ok = empty($missing) && !empty($step['required']);
    if (!empty($step['required'])) {
        $checks++;
        if ($ok) {
            $pass++;
        }
    }
    printf(
        "turn %d: %s -> %s | cited=%s | %s\n",
        $step['t'], $step['q'], $ok ? 'PASS' : (empty($step['required']) ? '(distractor)' : 'FAIL'),
        $cited > 0 ? 'Y' : 'N', substr($ans, 0, 90)
    );
    if (!$ok && !empty($step['required'])) {
        echo "         missing=" . implode(',', $missing) . "\n";
    }
    // append the exchange so the conversation actually grows
    $history[] = p_turn('user', $step['q']);
    $history[] = p_turn('assistant', $ans);
}

echo str_repeat('-', 70) . "\n";
printf("PERSISTENCE: %d/%d recall checks passed (%s)\n", $pass, $checks, $model);
echo "\ndone\n";
