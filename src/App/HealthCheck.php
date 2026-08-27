<?php

namespace App;

class HealthCheck
{
    public function check(): \stdClass
    {
        $status = new \stdClass();
        $status->database = $this->checkDatabase();
        $status->redis = $this->checkRedis();
        $ai = $this->checkAi();
        $status->ai = $ai['online'];
        $status->model_name = $ai['model'];
        
        $status->all_operational = $status->database
            && $status->redis
            && $status->ai;

        return $status;
    }

    private function checkDatabase(): bool
    {
        try {
            $db = new Database();
            $db->initTables();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Cache::getClient()->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkAi(): array
    {
        $host = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');

        $ch = curl_init("{$host}/props");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($response)) {
            $data = json_decode($response, true);
            return ['online' => true, 'model' => $data['model_alias'] ?? null];
        }

        $modelsUrl = "{$host}/models";
        $ch = curl_init($modelsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($response)) {
            $data = json_decode($response, true);
            $model = $data['data'][0]['id'] ?? ($data['models'][0]['name'] ?? null);
            return ['online' => true, 'model' => $model];
        }

        return ['online' => false, 'model' => null];
    }

    private function testUrl(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0;
    }

    private function fetchUrl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $response !== false) {
            return $response;
        }
        return null;
    }
}