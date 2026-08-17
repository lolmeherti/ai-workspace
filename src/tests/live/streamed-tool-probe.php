<?php

declare(strict_types=1);

/*
 * Phase 1 live probe — tool-capable streaming behavior (Gate 1).
 *
 * Establishes, against the ACTUAL running llama.cpp + model/template, the exact
 * SSE ordering of `reasoning_content`, `content`, `tool_calls`, and
 * `finish_reason` on streamed turns. No Localsy components are exercised — this
 * is a raw curl stream against the OpenAI-compatible endpoint. It exists to
 * answer Gate 1 before Phase 2 (single-pass tool-capable streaming):
 *
 *   1. On a streamed tool turn, does the model emit any normal delta.content
 *      before the first delta.tool_calls?
 *   2. Does it cleanly choose one path (reasoning -> content -> stop, or
 *      reasoning -> tool_calls -> finish_reason=tool_calls)?
 *   3. Exact ordering of the four fields.
 *
 * Cases (each streamed, temperature 0.2):
 *   A  no tools param, trivial        — baseline clean answer
 *   B  tools attached, no tool needed — the Phase 2 "normal turn" path
 *   C  tools attached, clear search   — the Phase 2 "tool turn" path
 *   D  tools attached, reasoning+tool — reasoning before a tool decision
 *   E  tools attached, multi-query    — fragmented tool arguments
 *   F  tools attached, ambiguous      — could answer OR search (content-before-tool probe)
 *
 * Requires the llama.cpp server running. Run:
 *   docker exec ai_php_web php /var/www/html/tests/live/streamed-tool-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;

Config::load(dirname(__DIR__, 2));

$apiUrl = rtrim((string) Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
$model  = (string) Config::get('LLM_MODEL_NAME', 'local-model');
$endpoint = $apiUrl . '/chat/completions';

function toolSchemas(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'search_local',
                'description' => "Search the user's local files and long-term memories for information. Use for anything in the user's personal data.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Search queries. Include synonyms and alternate phrasings.',
                        ],
                    ],
                    'required' => ['queries'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'search_web',
                'description' => 'Search the web for current information, facts, news, or anything beyond the user\'s personal data. Be focused — use only the most relevant query terms.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Focused web search queries. Keep it tight.',
                        ],
                    ],
                    'required' => ['queries'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'search_calendar',
                'description' => "Search the user's calendar for tasks, events, and todo items.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Calendar search queries.',
                        ],
                    ],
                    'required' => ['queries'],
                ],
            ],
        ],
    ];
}

function streamOnce(string $endpoint, string $model, array $messages, ?array $tools, int $maxTokens, float $temp): array
{
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'stream' => true,
        'stream_options' => ['include_usage' => true],
        'temperature' => $temp,
        'max_tokens' => $maxTokens,
    ];
    if ($tools !== null) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $events = [];
    $content = '';
    $reasoning = '';
    $toolCalls = [];
    $argFragments = [];
    $finishReason = null;
    $startTime = microtime(true);
    $firstDeltaMs = null;

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: text/event-stream']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
        &$events, &$content, &$reasoning, &$toolCalls, &$argFragments,
        &$finishReason, &$firstDeltaMs, $startTime
    ) {
        foreach (explode("\n", $data) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ') || $line === 'data: [DONE]') {
                continue;
            }
            $json = json_decode(substr($line, 6), true);
            if (!is_array($json)) {
                continue;
            }
            if ($firstDeltaMs === null) {
                $firstDeltaMs = microtime(true) - $startTime;
            }
            $delta = $json['choices'][0]['delta'] ?? [];
            $fr = $json['choices'][0]['finish_reason'] ?? null;
            if ($fr !== null) {
                $finishReason = $fr;
                $events[] = 'finish_reason=' . $fr;
            }
            if (isset($delta['reasoning_content'])) {
                $reasoning .= $delta['reasoning_content'];
                $events[] = 'reasoning_content';
            }
            if (isset($delta['content'])) {
                $content .= $delta['content'];
                $events[] = 'content';
            }
            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (!isset($toolCalls[$idx])) {
                        $toolCalls[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                        $argFragments[$idx] = 0;
                    }
                    if (isset($tc['id'])) {
                        $toolCalls[$idx]['id'] .= $tc['id'];
                    }
                    if (isset($tc['function']['name'])) {
                        $toolCalls[$idx]['name'] .= $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $toolCalls[$idx]['arguments'] .= $tc['function']['arguments'];
                        $argFragments[$idx]++;
                    }
                }
                $events[] = 'tool_calls';
            }
        }
        return strlen($data);
    });

    $result = curl_exec($ch);
    $error = $result === false ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'events' => $events,
        'content' => $content,
        'reasoning' => $reasoning,
        'tool_calls' => $toolCalls,
        'arg_fragments' => $argFragments,
        'finish_reason' => $finishReason,
        'ttft_ms' => $firstDeltaMs === null ? null : (int) round($firstDeltaMs * 1000),
        'total_ms' => (int) round((microtime(true) - $startTime) * 1000),
        'error' => $error,
    ];
}

function firstIdx(array $events, string $needle): ?int
{
    foreach ($events as $i => $e) {
        if (str_starts_with($e, $needle)) {
            return $i;
        }
    }
    return null;
}

function printCase(string $label, array $messages, ?array $tools, int $maxTokens, float $temp, string $endpoint, string $model): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "CASE {$label}\n";
    echo '  user: ' . str_replace("\n", ' ', (string) ($messages[count($messages) - 1]['content'] ?? '')) . "\n";
    echo '  tools: ' . ($tools === null ? 'NONE' : count($tools) . ' attached (auto)') . "\n";

    $r = streamOnce($endpoint, $model, $messages, $tools, $maxTokens, $temp);

    if ($r['error'] !== null) {
        echo "  ERROR: {$r['error']}\n";
        return;
    }

    $ev = $r['events'];
    $ci = firstIdx($ev, 'content');
    $ti = firstIdx($ev, 'tool_calls');
    $hasContent = $ci !== null;
    $hasTools = $ti !== null;
    $contentBeforeTool = $hasContent && $hasTools && $ci < $ti;
    $contentAfterTool = $hasContent && $hasTools && $ci > $ti;

    echo '  finish_reason: ' . var_export($r['finish_reason'], true) . "\n";
    echo '  ttft_ms: ' . var_export($r['ttft_ms'], true) . '  total_ms: ' . var_export($r['total_ms'], true) . "\n";
    echo '  event order (' . count($ev) . " deltas):\n    " . implode(' -> ', $ev) . "\n";
    echo '  reasoning_chars: ' . mb_strlen($r['reasoning']) . "  content_chars: " . mb_strlen($r['content']) . "\n";
    echo '  content_before_first_tool_call: ' . ($contentBeforeTool ? 'YES' : 'no') . "\n";
    echo '  content_after_tool_call: ' . ($contentAfterTool ? 'YES' : 'no') . "\n";

    if ($hasTools) {
        foreach ($r['tool_calls'] as $idx => $tc) {
            $frag = $r['arg_fragments'][$idx] ?? 0;
            echo "  tool_call[{$idx}]: name={$tc['name']}  arg_fragments={$frag}\n";
            echo "    arguments: {$tc['arguments']}\n";
        }
    }

    if ($hasContent && mb_strlen($r['content']) > 0) {
        $preview = mb_substr($r['content'], 0, 120);
        echo '  content preview: ' . str_replace("\n", ' ', $preview) . "\n";
    }
}

$tools = toolSchemas();
$temp = 0.2;

echo "============================================================\n";
echo "PHASE 1 — STREAMED-TOOL PROBE (live llama.cpp)\n";
echo "============================================================\n";
printf("endpoint: %s\nmodel: %s\ntemp: %.1f\n", $endpoint, $model, $temp);

printCase(
    'A — no tools param, trivial',
    [['role' => 'user', 'content' => 'Reply with exactly one word: hello']],
    null,
    128,
    $temp,
    $endpoint,
    $model
);

printCase(
    'B — tools attached, no tool needed',
    [['role' => 'user', 'content' => 'What is 2+2? Answer directly, do not use any tools.']],
    $tools,
    128,
    $temp,
    $endpoint,
    $model
);

printCase(
    'C — tools attached, clear web search',
    [['role' => 'user', 'content' => 'Search the web for the current 2026 Formula 1 drivers championship standings.']],
    $tools,
    2048,
    $temp,
    $endpoint,
    $model
);

printCase(
    'D — tools attached, reasoning + tool',
    [['role' => 'user', 'content' => 'Think step by step about what you need to look up, then search the web for today\'s weather in Vienna, Austria.']],
    $tools,
    2048,
    $temp,
    $endpoint,
    $model
);

printCase(
    'E — tools attached, multi-query (fragmented args)',
    [['role' => 'user', 'content' => 'Search the web for these three separate topics: the latest AI news, recent quantum computing breakthroughs, and the current price of gold.']],
    $tools,
    2048,
    $temp,
    $endpoint,
    $model
);

printCase(
    'F — tools attached, ambiguous (answer OR search)',
    [['role' => 'user', 'content' => 'What is the weather like in Vienna right now?']],
    $tools,
    2048,
    $temp,
    $endpoint,
    $model
);

echo "\n" . str_repeat('=', 70) . "\n";
echo "PROBE COMPLETE\n";
