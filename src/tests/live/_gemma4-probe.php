<?php

declare(strict_types=1);

// Temporary live verification probe for the gemma4 runtime (embedded template,
// off-only reasoning). Answers handoff Q#5:
//   1. reasoning_effort=none -> 0 reasoning (off-mechanism)
//   2. where Gemma thinking lands: reasoning_content vs content (markers)
// Reads the real .env, exercises AgentManager, plus raw non-streaming calls to
// inspect the un-stripped response. Deleted after use.

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;

Config::load('/var/www/html');

echo "=== PHP sees (real .env) ===\n";
echo "MODEL_ID      = " . Config::get('LLM_MODEL_ID', '?') . "\n";
echo "RUNTIME_POLICY= " . Config::get('LLM_RUNTIME_POLICY', '?') . "\n";
echo "SAMPLING      = " . Config::get('LLM_SAMPLING', '?') . "\n";
echo "REASONING_BUD = " . Config::get('LLM_REASONING_BUDGET', '?') . "\n\n";

$agent = new AgentManager();
$prompt = 'Explain in a few sentences why a large language model might give a wrong answer, and how to mitigate it.';

function probe(AgentManager $agent, string $label, array $messages, ?string $mode): array
{
    $agent->chat($messages, false, null, null, 'gemma4_probe', $mode);
    printf("%-24s reasoning=%5d content=%5d\n", $label, $agent->lastReasoningChars, $agent->lastContentChars);
    return ['reasoning' => $agent->lastReasoningChars, 'content' => $agent->lastContentChars];
}

echo "=== off (instruct) vs thinking (default) via AgentManager ===\n";
probe($agent, 'instruct (none)', [['role' => 'user', 'content' => $prompt]], 'instruct');
probe($agent, 'thinking (default)', [['role' => 'user', 'content' => $prompt]], 'thinking');

echo "\n=== raw non-streaming: where does thinking land? ===\n";
$api = rtrim(Config::get('LLM_API_URL', 'http://172.29.80.1:1234/v1'), '/');
$modelName = Config::get('LLM_MODEL_NAME', 'local-model');

function rawCall(string $api, string $modelName, array $messages, array $extra = []): array
{
    $payload = array_merge([
        'model' => $modelName,
        'messages' => $messages,
        'stream' => false,
        'max_tokens' => 512,
    ], $extra);
    $ch = curl_init($api . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 300,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        echo "RAW CALL FAILED: $err\n";
        return [];
    }
    $j = json_decode($raw, true);
    $msg = $j['choices'][0]['message'] ?? [];
    return [
        'finish' => $j['choices'][0]['finish_reason'] ?? '?',
        'reasoning' => $msg['reasoning_content'] ?? '',
        'content' => $msg['content'] ?? '',
    ];
}

function dumpRaw(string $label, array $r): void
{
    printf("%s: finish=%s reasoning_content_len=%d content_len=%d\n",
        $label, $r['finish'], mb_strlen($r['reasoning']), mb_strlen($r['content']));
    printf("  content_has_<|channel>thought=%s  content_has_<think>=%s\n",
        str_contains($r['content'], '<|channel>thought') ? 'YES' : 'no',
        (str_contains($r['content'], '<think') || str_contains($r['content'], '<thinking')) ? 'YES' : 'no');
    if ($r['reasoning'] !== '') {
        printf("  reasoning_preview=%s\n", mb_substr($r['reasoning'], 0, 100));
    }
    printf("  content_preview=%s\n", mb_substr($r['content'], 0, 100));
}

dumpRaw('thinking (no field)', rawCall($api, $modelName, [['role' => 'user', 'content' => $prompt]]));
dumpRaw('instruct (reasoning_effort=none)', rawCall($api, $modelName, [['role' => 'user', 'content' => $prompt]], ['reasoning_effort' => 'none']));
