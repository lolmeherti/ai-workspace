<?php

declare(strict_types=1);

/*
 * P3 — integrated first-pass live validation.
 *
 * Drives ChatManager::firstPass (via reflection) against the live model to
 * verify the hard inference budget on the FIRST pass:
 *   - exactly 1 LLM inference per turn;
 *   - normal turns: finish_reason=stop, no tool_calls, answer streams;
 *   - tool turns: tool_calls assembled, pre-decision reasoning DISCARDED;
 *   - content_before_tool is detected and reported.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/integrated-first-pass.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Services\PromptAssemblyService;

Config::load(dirname(__DIR__, 2));

final class CountingAgent extends AgentManager
{
    public int $calls = 0;
    public function chatToolCapable(array $messages, array $tools, string $toolChoice, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): array
    {
        $this->calls++;
        return parent::chatToolCapable($messages, $tools, $toolChoice, $streamCallback, $temperature);
    }
}

$db = new Database();
$db->initTables();

$agent = new CountingAgent();
$cm = new ChatManager($db, $agent);
$pas = new PromptAssemblyService($db, '/tmp');

$rc = new ReflectionClass(ChatManager::class);
$m = $rc->getMethod('firstPass');
$m->setAccessible(true);

$cases = [
    ['hello', null],
    ['thank you', null],
    ['explain the TLS handshake step by step', null],
    ["what's the weather in Vienna right now?", 'search_web'],
    ['find my files about the project', 'search_local'],
    ["what's on my calendar this week?", 'search_calendar'],
    ['search for "climate change" and "renewable energy"', 'search_web'],
    ["what's 2+2?", null],
];

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    printf("    [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
    $cond ? $pass++ : $fail++;
}

echo "INTEGRATED FIRST PASS (live)\n";
echo 'model: ' . (string) Config::get('LLM_MODEL_NAME', 'local-model') . "\n\n";

foreach ($cases as [$query, $expectedTool]) {
    $agent->calls = 0;
    $reasoningReleased = false;
    $tokenCount = 0;

    $emit = function (string $event, array $data = []) use (&$reasoningReleased, &$tokenCount) {
        if ($event === 'reasoning') {
            $reasoningReleased = true;
        }
        if ($event === 'token') {
            $tokenCount++;
        }
    };

    $systemPrompt = $pas->buildSystemPrompt($query) . $pas->dateContextLine();
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $query],
    ];

    $t0 = microtime(true);
    $result = $m->invoke($cm, $messages, $emit);
    $elapsed = (int) round((microtime(true) - $t0) * 1000);

    $toolName = $result['tool_calls'][0]['function']['name'] ?? null;
    $finish = $result['finish_reason'] ?? '?';
    $contentBeforeTool = ($tokenCount > 0 && $toolName !== null);

    echo "QUERY: {$query}\n";
    echo "  finish={$finish} tool=" . ($toolName ?? '-') . " calls={$agent->calls} ms={$elapsed} reasoning_released=" . ($reasoningReleased ? 'yes' : 'no') . " content_chunks={$tokenCount}" . ($contentBeforeTool ? ' CONTENT_BEFORE_TOOL' : '') . "\n";

    check('exactly 1 LLM inference (first pass)', $agent->calls === 1);

    if ($expectedTool === null) {
        check('normal turn: finish=stop, no tool_calls', $finish === 'stop' && $toolName === null);
    } else {
        check("tool turn: selected {$expectedTool}", $toolName === $expectedTool);
        check('tool turn: pre-decision reasoning discarded', !$reasoningReleased);
    }

    echo "\n";
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
