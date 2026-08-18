<?php

namespace App;

use App\Services\ModelLock;
use Exception;

class AgentManager
{
    private string $apiUrl;
    private string $modelName;
    public ?array $lastUsage = null;
    /** Server-reported prefill/decode timings (prompt_ms, prompt_n, cache_n, ...) from the last chat() call. */
    public ?array $lastTimings = null;

    /** Per-call performance log for the current request (purpose + server timings + stream phases). */
    public array $callLog = [];

    /** Reset the per-call performance log (called at the start of each turn). */
    public function resetCallLog(): void
    {
        $this->callLog = [];
    }

    /**
     * Normalize one LLM call's server timings + stream phases into a compact
     * record and append it to the per-request call log. Reasoning vs content are
     * split at the stream level (llama.cpp reports them as one "predicted" stream).
     */
    private function finalizeCall(string $purpose, float $startTime, ?array $timings, ?array $usage, ?float $firstReasoningTs, ?float $firstContentTs, int $reasoningChars, int $contentChars): void
    {
        $end = microtime(true);
        $elapsedMs = ($end - $startTime) * 1000;
        // Reasoning = first reasoning token -> first content token (or end of
        // the stream when the call emits no content, e.g. a tool decision).
        $reasoningMs = ($firstReasoningTs !== null)
            ? max(0.0, ((($firstContentTs !== null) ? $firstContentTs : $end) - $firstReasoningTs) * 1000)
            : 0.0;
        // Content = first content token -> end (0 when there was no content).
        $contentMs = ($firstContentTs !== null) ? max(0.0, ($end - $firstContentTs) * 1000) : 0.0;

        $t = $timings ?? [];
        $u = $usage ?? [];

        $this->callLog[] = [
            'purpose' => $purpose,
            'elapsed_ms' => (int) round($elapsedMs),
            'prompt_ms' => (float) ($t['prompt_ms'] ?? 0),
            'prompt_n' => (int) ($t['prompt_n'] ?? 0),
            'prompt_tps' => (float) ($t['prompt_per_second'] ?? 0),
            'cache_n' => (int) ($t['cache_n'] ?? 0),
            'pred_ms' => (float) ($t['predicted_ms'] ?? 0),
            'pred_n' => (int) ($t['predicted_n'] ?? 0),
            'pred_tps' => (float) ($t['predicted_per_second'] ?? 0),
            'reasoning_ms' => (int) round($reasoningMs),
            'reasoning_tok' => (int) ceil($reasoningChars / 4),
            'content_ms' => (int) round($contentMs),
            'content_tok' => (int) ceil($contentChars / 4),
            'prompt_tokens' => (int) ($u['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($u['completion_tokens'] ?? 0),
        ];
    }

    public function __construct()
    {
        $this->apiUrl = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
        $this->modelName = Config::get('LLM_MODEL_NAME', 'local-model');
    }

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): string
    {
        $endpoint = $this->apiUrl . '/chat/completions';
        $finalTemperature = $temperature ?? (float) Config::get('DEFAULT_CHAT_TEMP', 0.5);

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'temperature' => $finalTemperature,
            'max_tokens' => 4096,
        ];

        $msgCount = count($messages);
        $estTokens = 0;
        foreach ($messages as $m) {
            $content = is_array($m['content'] ?? null) ? ($m['content'][0]['text'] ?? '') : ($m['content'] ?? '');
            $estTokens += (int)(mb_strlen($content) / 4);
        }

        \App\Logger::logEvent('llm_request_start', "LLM request: {$msgCount} messages, ~{$estTokens} tokens", [
            'message_count' => $msgCount,
            'estimated_tokens' => $estTokens,
            'stream' => $stream,
            'temperature' => $finalTemperature,
        ], 'info', 'AgentManager::chat');

        $owns = false;
        $lockToken = null;
        if (!ModelLock::isOwner()) {
            $lockToken = ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
            $owns = true;
        }

        try {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: text/event-stream'
            ]);

            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            $fullResponse = '';
            $lastUsage = null;
            $lastTimings = null;
            $startTime = microtime(true);
            $firstReasoningTs = null;
            $firstContentTs = null;
            $reasoningChars = 0;
            $contentChars = 0;

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($streamCallback, &$fullResponse, &$lastUsage, &$lastTimings, &$firstReasoningTs, &$firstContentTs, &$reasoningChars, &$contentChars, $stream) {
                $lines = explode("\n", $data);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                        $json = json_decode(substr($line, 6), true);

                        if (isset($json['usage'])) {
                            $lastUsage = $json['usage'];
                        }

                        if (isset($json['timings'])) {
                            $lastTimings = $json['timings'];
                        }

                        if (isset($json['choices'][0]['delta']['reasoning_content'])) {
                            $chunk = $json['choices'][0]['delta']['reasoning_content'];
                            if ($firstReasoningTs === null) {
                                $firstReasoningTs = microtime(true);
                            }
                            $reasoningChars += mb_strlen($chunk);
                            if ($stream && $streamCallback !== null) {
                                $streamCallback($chunk, 'reasoning');
                            }
                        }

                        if (isset($json['choices'][0]['delta']['content'])) {
                            $chunk = $json['choices'][0]['delta']['content'];
                            if ($firstContentTs === null) {
                                $firstContentTs = microtime(true);
                            }
                            $contentChars += mb_strlen($chunk);
                            $fullResponse .= $chunk;

                            if ($stream && $streamCallback !== null) {
                                $streamCallback($chunk, 'content');
                            }
                        }
                    }
                }
                return strlen($data);
            });

            $result = curl_exec($ch);
            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);

                \App\Logger::logEvent('llm_connection_error', "LLM connection failed after {$elapsed}ms: {$error}", [
                    'error' => $error,
                    'elapsed_ms' => $elapsed,
                    'endpoint' => $endpoint,
                ], 'error', 'AgentManager::chat');

                \App\Logger::critical("cURL Error connecting to LLM at {$endpoint}", ['error' => $error]);
                throw new Exception("cURL Error connecting to LLM at {$endpoint}: " . $error);
            }

            curl_close($ch);

            $this->lastUsage = $lastUsage;
            $this->lastTimings = $lastTimings;
            $this->finalizeCall($purpose ?? 'answer', $startTime, $lastTimings, $lastUsage, $firstReasoningTs, $firstContentTs, $reasoningChars, $contentChars);

            $responseLen = strlen($fullResponse);
            $level = $responseLen < 20 ? 'warn' : 'info';
            \App\Logger::logEvent('llm_response_done', "LLM response: {$responseLen} chars in {$elapsed}ms", [
                'response_length' => $responseLen,
                'elapsed_ms' => $elapsed,
                'tokens_used' => $lastUsage,
            ], $level, 'AgentManager::chat');

            if ($responseLen < 20 && $responseLen > 0) {
                \App\Logger::logEvent('llm_partial_response', "LLM returned unusually short response ({$responseLen} chars)", [
                    'response_preview' => mb_substr($fullResponse, 0, 500),
                    'elapsed_ms' => $elapsed,
                ], 'warn', 'AgentManager::chat');
            }

            return \App\ThoughtExtractor::strip($fullResponse);
        } finally {
            if ($owns) {
                ModelLock::release($lockToken);
            }
        }
    }

    /**
     * Tool-capable streaming call (the integrated first pass). Streams
     * reasoning + content to the callback (fn(string $chunk, string $type)
     * where $type is 'reasoning'|'content'), assembles streamed tool_calls by
     * index (function name + arguments fragments are concatenated per index),
     * and returns a structured result so the caller can branch on
     * finish_reason. This is the sibling of chat(); chat()'s contract is
     * unchanged.
     *
     * @param array $messages
     * @param array $tools        JSON-schema function tool definitions
     * @param string $toolChoice  'auto' | 'required' | 'none'
     * @return array{finish_reason: string, content: string, tool_calls: ?array, usage: ?array}
     */
    public function chatToolCapable(array $messages, array $tools, string $toolChoice, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): array
    {
        $endpoint = $this->apiUrl . '/chat/completions';
        $finalTemperature = $temperature ?? (float) Config::get('DEFAULT_CHAT_TEMP', 0.5);

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'temperature' => $finalTemperature,
            'max_tokens' => 4096,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ];

        $msgCount = count($messages);
        $estTokens = 0;
        foreach ($messages as $m) {
            $content = is_array($m['content'] ?? null) ? ($m['content'][0]['text'] ?? '') : ($m['content'] ?? '');
            $estTokens += (int)(mb_strlen($content) / 4);
        }

        \App\Logger::logEvent('llm_request_start', "LLM tool-capable request: {$msgCount} messages, ~{$estTokens} tokens, " . count($tools) . " tools", [
            'message_count' => $msgCount,
            'estimated_tokens' => $estTokens,
            'tool_count' => count($tools),
            'tool_choice' => $toolChoice,
            'temperature' => $finalTemperature,
        ], 'info', 'AgentManager::chatToolCapable');

        $owns = false;
        $lockToken = null;
        if (!ModelLock::isOwner()) {
            $lockToken = ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
            $owns = true;
        }

        $fullResponse = '';
        $lastUsage = null;
        $lastTimings = null;
        $finishReason = null;
        $toolCalls = [];
        $toolCallSeen = [];
        $elapsed = 0;
        $firstReasoningTs = null;
        $firstContentTs = null;
        $reasoningChars = 0;
        $contentChars = 0;

        try {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: text/event-stream'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            $startTime = microtime(true);

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($streamCallback, &$fullResponse, &$lastUsage, &$lastTimings, &$finishReason, &$toolCalls, &$toolCallSeen, &$firstReasoningTs, &$firstContentTs, &$reasoningChars, &$contentChars) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data: ') || $line === 'data: [DONE]') continue;
                    $json = json_decode(substr($line, 6), true);
                    if (!is_array($json)) continue;

                    if (isset($json['usage'])) {
                        $lastUsage = $json['usage'];
                    }

                    if (isset($json['timings'])) {
                        $lastTimings = $json['timings'];
                    }

                    $choice = $json['choices'][0] ?? [];
                    if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null && $choice['finish_reason'] !== '') {
                        $finishReason = $choice['finish_reason'];
                    }

                    $delta = $choice['delta'] ?? [];

                    if (isset($delta['reasoning_content'])) {
                        $chunk = $delta['reasoning_content'];
                        if ($firstReasoningTs === null) {
                            $firstReasoningTs = microtime(true);
                        }
                        $reasoningChars += mb_strlen($chunk);
                        if ($streamCallback !== null) {
                            $streamCallback($chunk, 'reasoning');
                        }
                    }

                    if (isset($delta['content'])) {
                        $chunk = $delta['content'];
                        if ($firstContentTs === null) {
                            $firstContentTs = microtime(true);
                        }
                        $contentChars += mb_strlen($chunk);
                        $fullResponse .= $chunk;
                        if ($streamCallback !== null) {
                            $streamCallback($chunk, 'content');
                        }
                    }

                    if (isset($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (!isset($toolCallSeen[$idx])) {
                                $toolCallSeen[$idx] = true;
                                $toolCalls[$idx] = [
                                    'id' => $tc['id'] ?? '',
                                    'type' => 'function',
                                    'function' => ['name' => '', 'arguments' => ''],
                                ];
                            }
                            if (!empty($tc['id'])) {
                                $toolCalls[$idx]['id'] = $tc['id'];
                            }
                            $fn = $tc['function'] ?? [];
                            if (!empty($fn['name'])) {
                                $toolCalls[$idx]['function']['name'] .= $fn['name'];
                            }
                            if (isset($fn['arguments']) && $fn['arguments'] !== '') {
                                $toolCalls[$idx]['function']['arguments'] .= $fn['arguments'];
                            }
                        }
                    }
                }
                return strlen($data);
            });

            $result = curl_exec($ch);
            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                \App\Logger::logEvent('llm_connection_error', "LLM tool-capable request failed after {$elapsed}ms: {$error}", [
                    'error' => $error,
                    'elapsed_ms' => $elapsed,
                    'endpoint' => $endpoint,
                ], 'error', 'AgentManager::chatToolCapable');
                \App\Logger::critical("cURL Error connecting to LLM at {$endpoint}", ['error' => $error]);
                throw new Exception("cURL Error connecting to LLM at {$endpoint}: " . $error);
            }

            curl_close($ch);
        } finally {
            if ($owns) {
                ModelLock::release($lockToken);
            }
        }

        $this->lastUsage = $lastUsage;
        $this->lastTimings = $lastTimings;
        $this->finalizeCall($purpose ?? 'firstpass', $startTime, $lastTimings, $lastUsage, $firstReasoningTs, $firstContentTs, $reasoningChars, $contentChars);

        ksort($toolCalls);
        $toolCalls = array_values($toolCalls);

        $responseLen = strlen($fullResponse);
        \App\Logger::logEvent('llm_tool_capable_done', "LLM tool-capable response: finish={$finishReason}, {$responseLen} chars, " . count($toolCalls) . " tool calls in {$elapsed}ms", [
            'finish_reason' => $finishReason,
            'response_length' => $responseLen,
            'tool_call_count' => count($toolCalls),
            'tokens_used' => $lastUsage,
            'elapsed_ms' => $elapsed,
        ], 'info', 'AgentManager::chatToolCapable');

        return [
            'finish_reason' => $finishReason ?? 'stop',
            'content' => \App\ThoughtExtractor::strip($fullResponse),
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
            'usage' => $lastUsage,
        ];
    }

    /**
     * Non-streaming call with native function-calling support.
     * Sends tool definitions as JSON schema; the API enforces format.
     * Returns structured result so the caller can branch on finish_reason.
     *
     * @return array{finish_reason: string, content: ?string, tool_calls: ?array, usage: ?array}
     */
    public function chatWithTools(array $messages, array $tools, string $toolChoice = 'auto', ?float $temperature = null, int $maxTokens = 4096, ?string $purpose = null): array
    {
        $endpoint = $this->apiUrl . '/chat/completions';
        $finalTemperature = $temperature ?? (float) Config::get('DEFAULT_CHAT_TEMP', 0.5);

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => false,
            'temperature' => $finalTemperature,
            'max_tokens' => $maxTokens,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ];

        $msgCount = count($messages);
        \App\Logger::logEvent('llm_tool_request_start', "LLM tool request: {$msgCount} messages, " . count($tools) . " tools", [
            'message_count' => $msgCount,
            'tool_count' => count($tools),
            'tool_choice' => $toolChoice,
        ], 'info', 'AgentManager::chatWithTools');

        $owns = false;
        $lockToken = null;
        if (!ModelLock::isOwner()) {
            $lockToken = ModelLock::acquireOrBusy(ModelLock::DEFAULT_TTL_MS);
            $owns = true;
        }

        try {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            $startTime = microtime(true);
            $result = curl_exec($ch);
            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);

                \App\Logger::logEvent('llm_connection_error', "LLM tool request failed after {$elapsed}ms: {$error}", [
                    'error' => $error,
                    'elapsed_ms' => $elapsed,
                    'endpoint' => $endpoint,
                ], 'error', 'AgentManager::chatWithTools');

                throw new \Exception("cURL Error connecting to LLM at {$endpoint}: " . $error);
            }

            curl_close($ch);

            $response = json_decode($result, true);
            if (!$response) {
                \App\Logger::logEvent('llm_tool_parse_error', "Failed to parse LLM tool response", [
                    'raw_len' => strlen($result),
                    'raw_preview' => mb_substr($result, 0, 500),
                ], 'error', 'AgentManager::chatWithTools');

                return [
                    'finish_reason' => 'error',
                    'content' => null,
                    'tool_calls' => null,
                    'usage' => null,
                ];
            }

            $choice = $response['choices'][0] ?? [];
            $finishReason = $choice['finish_reason'] ?? 'stop';
            $content = $choice['message']['content'] ?? null;
            $toolCalls = $choice['message']['tool_calls'] ?? null;
            $usage = $response['usage'] ?? null;
            $timings = $response['timings'] ?? null;

            $this->lastUsage = $usage;
            $this->lastTimings = $timings;
            $this->finalizeCall($purpose ?? 'tools', $startTime, $timings, $usage, null, null, 0, mb_strlen($content ?? ''));

            $toolCallDetails = [];
            if ($toolCalls) {
                foreach ($toolCalls as $tc) {
                    $fn = $tc['function'] ?? [];
                    $toolCallDetails[] = [
                        'name' => $fn['name'] ?? '?',
                        'arguments' => $fn['arguments'] ?? '{}',
                    ];
                }
            }

            \App\Logger::logEvent('llm_tool_response_done', "LLM tool response: finish={$finishReason}, " . ($toolCalls ? count($toolCalls) . " tool calls" : 'no tool calls') . ", {$elapsed}ms", [
                'finish_reason' => $finishReason,
                'has_content' => $content !== null && $content !== '',
                'tool_call_count' => $toolCalls ? count($toolCalls) : 0,
                'tool_calls' => $toolCallDetails,
                'elapsed_ms' => $elapsed,
                'tokens_used' => $usage,
            ], 'info', 'AgentManager::chatWithTools');

            return [
                'finish_reason' => $finishReason,
                'content' => $content,
                'tool_calls' => $toolCalls,
                'usage' => $usage,
            ];
        } finally {
            if ($owns) {
                ModelLock::release($lockToken);
            }
        }
    }
}
