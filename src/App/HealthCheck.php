<?php

namespace App;

class HealthCheck
{
    public function check(): \stdClass
    {
        $status = new \stdClass();
        $status->database = $this->checkDatabase();
        $status->redis = $this->checkRedis();
        $status->searxng = $this->checkSearxng();
        $status->flaresolverr = $this->checkFlaresolverr();
        $status->ai = $this->checkAi();
        
        $status->all_operational = $status->database 
            && $status->redis 
            && $status->searxng 
            && $status->flaresolverr 
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

    private function checkSearxng(): bool
    {
        $host = rtrim(Config::get('SEARXNG_HOST', 'http://searxng:8080'), '/');
        return $this->testUrl($host);
    }

    private function checkFlaresolverr(): bool
    {
        $host = rtrim(Config::get('FLARESOLVERR_HOST', 'http://flaresolverr:8191'), '/');
        return $this->testUrl($host);
    }

    private function checkAi(): bool
    {
        $host = rtrim(Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/') . '/models';
        $configuredModel = Config::get('LLM_MODEL_NAME', 'local-model');

        $response = $this->fetchUrl($host);
        if ($response === null) {
            return false;
        }

        $data = json_decode($response, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            return false;
        }

        foreach ($data['data'] as $model) {
            if (isset($model['id']) && $model['id'] === $configuredModel) {
                return true;
            }
        }

        return false;
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