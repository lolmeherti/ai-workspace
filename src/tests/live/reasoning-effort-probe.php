<?php

declare(strict_types=1);

/*
 * reasoning_effort live probe — does none / low / medium / high actually change
 * the model's behavior under the current llama-server boot (--reasoning-budget 4096,
 * --jinja, --flash-attn, -ngl 999, --cache-type-k/v q8_0, etc.)?
 *
 * One raw non-streaming /chat/completions call per (task x effort). Observables:
 * elapsed_ms, completion_tokens, reasoning_content length, content length,
 * finish_reason. "Has an effect" = reasoning_content / completion_tokens /
 * latency actually VARY across effort levels. Flat across levels = the knob is
 * dead under the current boot flags (e.g. the budget cap or the model
 * overriding reasoning_effort).
 *
 * Self-contained fixtures (no DB, no PII, no external data). Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/reasoning-effort-probe.php
 * Optional endpoint override (bridge IP if host.docker.internal is unresolvable):
 *   docker exec ai_php_web php /var/www/html/tests/live/reasoning-effort-probe.php http://172.29.80.1:1234/v1
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;

Config::load(dirname(__DIR__, 2));

$base = $argv[1] ?? (string) Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1');
$base = rtrim($base, '/');

// -------- fixtures (self-contained, no PII) --------
$tasks = [
    'mechanical' => [
        'system' => "Extract each factual claim as its own line, each line beginning with [S#]. Output ONLY the claim lines. No preamble, no commentary.",
        'user'   => "The Eiffel Tower was completed in 1889 for the World's Fair. It stands 330 meters tall. Gustave Eiffel's company designed the tower. The tower is repainted every seven years. It has three visitor levels.",
    ],
    'reasoning' => [
        'system' => "Answer the question with a short explanation and a clear final answer.",
        'user'   => "A water tank has two inlet pipes and one drain. Pipe A fills the tank in 6 hours, pipe B fills it in 4 hours, and the drain empties it in 12 hours. The tank starts empty and all three are opened at once. How many hours until the tank is full?",
    ],
];

// effort levels, in order. 'absent' omits the field entirely (model default).
$levels = [
    'absent' => null,
    'none'   => 'none',
    'low'    => 'low',
    'medium' => 'medium',
    'high'   => 'high',
];

/**
 * GET JSON (used only to read the loaded model id).
 * @return array<string,mixed>|null
 */
function httpGetJson(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) {
        return null;
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/**
 * @return array<string,mixed>
 */
function postChat(string $base, string $model, array $task, ?string $effort): array
{
    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $task['system']],
            ['role' => 'user', 'content' => $task['user']],
        ],
        'temperature' => 0.2,
        'max_tokens'  => 8192,
        'stream'      => false,
    ];
    if ($effort !== null) {
        $payload['reasoning_effort'] = $effort;
    }

    $t0 = microtime(true);
    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 300,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

    if ($raw === false) {
        return ['error' => $err, 'elapsed_ms' => $elapsedMs];
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || isset($d['error'])) {
        $msg = substr((string) $raw, 0, 300);
        if (is_array($d) && isset($d['error'])) {
            if (is_array($d['error'])) {
                $msg = (string) ($d['error']['message'] ?? json_encode($d['error']));
            } else {
                $msg = (string) $d['error'];
            }
        }
        return ['error' => $msg, 'elapsed_ms' => $elapsedMs];
    }

    $msg   = $d['choices'][0]['message'] ?? [];
    $usage = $d['usage'] ?? [];

    return [
        'reasoning_len'     => mb_strlen((string) ($msg['reasoning_content'] ?? '')),
        'content_len'       => mb_strlen((string) ($msg['content'] ?? '')),
        'content_preview'   => trim((string) ($msg['content'] ?? '')),
        'completion_tokens' => (int) ($usage['completion_tokens'] ?? -1),
        'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? -1),
        'finish_reason'     => (string) ($d['choices'][0]['finish_reason'] ?? '?'),
        'elapsed_ms'        => $elapsedMs,
    ];
}

// -------- detect loaded model --------
$model = (string) Config::get('LLM_MODEL_NAME', 'local-model');
$models = httpGetJson($base . '/models');
if ($models !== null && isset($models['data'][0]['id']) && $models['data'][0]['id'] !== '') {
    $model = (string) $models['data'][0]['id'];
}

echo "REASONING_EFFORT PROBE (live)\n";
echo 'endpoint:    ' . $base . "\n";
echo 'model:       ' . $model . "\n";
echo "temperature: 0.2 | max_tokens: 8192 | stream: false\n\n";

$results = [];
foreach ($tasks as $taskName => $task) {
    echo "=== task: {$taskName} ===\n";
    foreach ($levels as $level => $effort) {
        $r = postChat($base, $model, $task, $effort);
        $results[$taskName][$level] = $r;
        if (isset($r['error'])) {
            printf("  %-7s ERROR: %s (%d ms)\n", $level, $r['error'], $r['elapsed_ms']);
        } else {
            printf(
                "  %-7s %6d ms | completion=%5d tok | reasoning=%5d ch | content=%5d ch | finish=%s\n",
                $level,
                $r['elapsed_ms'],
                $r['completion_tokens'],
                $r['reasoning_len'],
                $r['content_len'],
                $r['finish_reason']
            );
        }
    }
    echo "\n";
}

// -------- summary: reasoning_content chars by effort --------
echo "=== summary (reasoning_content chars) ===\n";
$header = str_pad('task', 12);
foreach (array_keys($levels) as $level) {
    $header .= sprintf(' | %-7s', $level);
}
echo $header . "\n";
foreach ($tasks as $taskName => $task) {
    $row = str_pad($taskName, 12);
    foreach ($levels as $level => $effort) {
        $r = $results[$taskName][$level];
        $v = isset($r['error']) ? 'ERR' : (string) $r['reasoning_len'];
        $row .= sprintf(' | %-7s', $v);
    }
    echo $row . "\n";
}

// -------- content previews (eyeball quality) --------
echo "\n=== content previews ===\n";
foreach ($tasks as $taskName => $task) {
    echo "[{$taskName}]\n";
    foreach ($levels as $level => $effort) {
        $r = $results[$taskName][$level];
        if (isset($r['error'])) {
            continue;
        }
        $preview = preg_replace('/\s+/', ' ', $r['content_preview']);
        if ($taskName === 'mechanical') {
            // extraction should be short — print in full
            echo "  {$level}: " . $preview . "\n";
        } else {
            echo "  {$level}: " . mb_substr($preview, 0, 160) . (mb_strlen($preview) > 160 ? '…' : '') . "\n";
        }
    }
    echo "\n";
}

echo "done\n";
