<?php

namespace App;

use Exception;

class AgentManager
{
    private string $apiUrl;
    private string $modelName;

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
            'stream' => $stream,
            'temperature' => $finalTemperature
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json')
        ]);
        // Short timeout for testing so it doesn't hang forever if the port is wrong
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); 

        $fullResponse = '';

        if ($stream) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($streamCallback, &$fullResponse) {
                $lines = explode("\n", $data);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    
                    if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                        $json = json_decode(substr($line, 6), true);
                        
                        if (isset($json['choices'][0]['delta']['content'])) {
                            $chunk = $json['choices'][0]['delta']['content'];
                            $fullResponse .= $chunk;
                            
                            if ($streamCallback !== null) {
                                $streamCallback($chunk);
                            }
                        }
                    }
                }
                return strlen($data);
            });
            
            $result = curl_exec($ch);
        } else {
            $result = curl_exec($ch);
            
            if ($result !== false) {
                $json = json_decode($result, true);
                if (isset($json['error'])) {
                    throw new Exception("LLM API Error: " . json_encode($json['error']));
                }
                $fullResponse = $json['choices'][0]['message']['content'] ?? '';
            }
        }

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error connecting to LLM at {$endpoint}: " . $error);
        }

        curl_close($ch);
        
        return $fullResponse;
    }
}