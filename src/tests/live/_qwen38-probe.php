<?php

declare(strict_types=1);

// Temporary live verification probe for the qwen38 runtime (fixed template).
// Reads the REAL .env (no overrides) and exercises the actual AgentManager
// code path. Deleted after use.

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;

Config::load('/var/www/html');

echo "=== PHP sees (real .env) ===\n";
echo "MODEL_ID      = " . Config::get('LLM_MODEL_ID', '?') . "\n";
echo "RUNTIME_POLICY= " . Config::get('LLM_RUNTIME_POLICY', '?') . "\n";
echo "SAMPLING      = " . Config::get('LLM_SAMPLING', '?') . "\n";
echo "CTX_SIZE      = " . Config::get('LLM_CTX_SIZE', '?') . "\n\n";

$agent = new AgentManager();

function probe(AgentManager $agent, string $label, array $messages, ?string $mode, ?string $effort = null): array
{
    $agent->chat($messages, false, null, null, 'qwen38_probe', $mode, $effort);
    $rc = $agent->lastReasoningChars;
    $cc = $agent->lastContentChars;
    $ct = $agent->lastUsage['completion_tokens'] ?? 0;
    $pt = $agent->lastUsage['prompt_tokens'] ?? 0;
    printf("%-26s reasoning=%5d content=%5d completion=%5d prompt=%5d\n", $label, $rc, $cc, $ct, $pt);
    return ['reasoning' => $rc, 'content' => $cc, 'prompt' => $pt];
}

$promptA = 'What is 23 * 17? Work through the multiplication step by step.';
$promptB = 'Explain in a few sentences why a large language model might give a wrong answer, and how to mitigate it.';

echo "=== off (instruct) vs thinking (default) ===\n";
probe($agent, 'instruct (none)', [['role' => 'user', 'content' => $promptA]], 'instruct');
probe($agent, 'thinking (default=medium)', [['role' => 'user', 'content' => $promptA]], 'thinking');

echo "\n=== graduated effort (prompt A) ===\n";
probe($agent, 'effort=low', [['role' => 'user', 'content' => $promptA]], 'thinking', 'low');
probe($agent, 'effort=medium', [['role' => 'user', 'content' => $promptA]], 'thinking', 'medium');
probe($agent, 'effort=high(xhigh)', [['role' => 'user', 'content' => $promptA]], 'thinking', 'high');

echo "\n=== graduated effort (prompt B) ===\n";
probe($agent, 'effort=low', [['role' => 'user', 'content' => $promptB]], 'thinking', 'low');
probe($agent, 'effort=high(xhigh)', [['role' => 'user', 'content' => $promptB]], 'thinking', 'high');

echo "\n=== deepseek separation (thinking must NOT leak <think> into content) ===\n";
$out = $agent->chat([['role' => 'user', 'content' => $promptB]], false, null, null, 'qwen38_probe', 'thinking');
printf("reasoning_chars=%d content_chars=%d content_has_<think>=%s\n",
    $agent->lastReasoningChars, $agent->lastContentChars,
    (str_contains($out, '<think') || str_contains($out, '<thinking')) ? 'YES(BAD)' : 'no(good)');

echo "\n=== tool-call round-trip (first pass via native function calling) ===\n";
$tools = [[
    'type' => 'function',
    'function' => [
        'name' => 'search_web',
        'description' => 'Search the web for current or factual information.',
        'parameters' => [
            'type' => 'object',
            'properties' => ['queries' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['queries'],
        ],
    ],
]];
$first = $agent->chatToolCapable(
    [['role' => 'user', 'content' => 'Use search_web to look up the current price of a Tesla Model 3, then tell me the result.']],
    $tools,
    'auto',
    null,
    null,
    'qwen38_firstpass'
);
$tc = $first['tool_calls'] ?? null;
printf("finish=%s tool_calls=%d\n", $first['finish_reason'], $tc ? count($tc) : 0);
if ($tc) {
    foreach ($tc as $c) {
        printf("  -> function=%s args=%s\n", $c['function']['name'] ?? '?', mb_substr($c['function']['arguments'] ?? '', 0, 120));
    }
}

echo "\n=== preserve-reasoning context note (2-turn, reasoning preserved?) ===\n";
$msgs = [
    ['role' => 'user', 'content' => 'Explain the concept of a Bloom filter in two sentences.'],
];
$r1 = probe($agent, 'turn1 (thinking)', $msgs, 'thinking');
$msgs[] = ['role' => 'assistant', 'content' => 'A Bloom filter is a space-efficient probabilistic set structure that can tell you an element is definitely absent or possibly present.'];
$msgs[] = ['role' => 'user', 'content' => 'Now give an example use case.'];
$r2 = probe($agent, 'turn2 (thinking)', $msgs, 'thinking');
printf("prompt growth turn1->turn2 = +%d tokens\n", $r2['prompt'] - $r1['prompt']);
