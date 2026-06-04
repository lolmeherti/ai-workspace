<?php

namespace App;

class LlmClient
{
    private string $apiUrl;
    private string $modelName;

    public function __construct()
    {
        $this->apiUrl = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
        $this->modelName = Config::get('LLM_MODEL_NAME', 'local-model');
    }

    public function chat(array $messages, bool $stream = false, callable $streamCallback = null): string
    {
        $endpoint = $this->apiUrl . '/chat/completions';
        
        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => $stream
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $fullResponse = '';

        if ($stream) {
            $buffer = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($streamCallback, &$fullResponse, &$buffer) {
                $buffer .= $data;
                
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    
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
            
            curl_exec($ch);
        } else {
            $response = curl_exec($ch);
            
            if ($response) {
                $json = json_decode($response, true);
                $fullResponse = $json['choices'][0]['message']['content'] ?? '';
            }
        }

        curl_close($ch);
        
        return $fullResponse;
    }
}