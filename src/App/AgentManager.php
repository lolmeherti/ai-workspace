<?php

namespace App;

use App\Services\ModelLock;
use Exception;

class AgentManager
{
    private string $apiUrl;
    private string $modelName;
    public ?array $lastUsage = null;

    public function __construct()
    {
        $this->apiUrl = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
        $this->modelName = Config::get('LLM_MODEL_NAME', 'local-model');
    }

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null): string
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
            $startTime = microtime(true);

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($streamCallback, &$fullResponse, &$lastUsage, $stream) {
                $lines = explode("\n", $data);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                        $json = json_decode(substr($line, 6), true);

                        if (isset($json['usage'])) {
                            $lastUsage = $json['usage'];
                        }

                        if (isset($json['choices'][0]['delta']['reasoning_content'])) {
                            $chunk = $json['choices'][0]['delta']['reasoning_content'];
                            if ($stream && $streamCallback !== null) {
                                $streamCallback($chunk, 'reasoning');
                            }
                        }

                        if (isset($json['choices'][0]['delta']['content'])) {
                            $chunk = $json['choices'][0]['delta']['content'];
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
     * Non-streaming call with native function-calling support.
     * Sends tool definitions as JSON schema; the API enforces format.
     * Returns structured result so the caller can branch on finish_reason.
     *
     * @return array{finish_reason: string, content: ?string, tool_calls: ?array, usage: ?array}
     */
    public function chatWithTools(array $messages, array $tools, string $toolChoice = 'auto'): array
    {
        $endpoint = $this->apiUrl . '/chat/completions';
        $finalTemperature = (float) Config::get('DEFAULT_CHAT_TEMP', 0.5);

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => false,
            'temperature' => $finalTemperature,
            'max_tokens' => 4096,
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

            $this->lastUsage = $usage;

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
