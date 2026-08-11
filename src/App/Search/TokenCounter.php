<?php

namespace App\Search;

use App\Config;

class TokenCounter
{
    private string $endpoint;
    private array $cache = [];

    public function __construct()
    {
        $apiUrl = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');
        $this->endpoint = dirname($apiUrl) . '/tokenize';
    }

    public function count(string $text): int
    {
        if (empty($text)) return 0;

        $hash = md5($text);
        if (isset($this->cache[$hash])) {
            return $this->cache[$hash];
        }

        $payload = json_encode(['content' => $text]);
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['tokens']) && is_array($data['tokens'])) {
                $count = count($data['tokens']);
                $this->cache[$hash] = $count;
                return $count;
            }
        }

        $fallback = (int)(mb_strlen($text) / 4);
        $this->cache[$hash] = $fallback;
        return $fallback;
    }

    public function countChunks(array $chunks): int
    {
        $total = 0;
        foreach ($chunks as $chunk) {
            $total += $this->count($chunk->text);
        }
        return $total;
    }
}
