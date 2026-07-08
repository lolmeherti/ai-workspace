<?php

namespace App;

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
    }
}
