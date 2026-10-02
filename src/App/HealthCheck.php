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

    public function checkAi(): array
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
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        if ($code === 200 && !empty($response)) {
            $data = json_decode($response, true);
            $model = $data['data'][0]['id'] ?? ($data['models'][0]['name'] ?? null);
            return ['online' => true, 'model' => $model];
        }

        // Offline: report what was probed and what came back, and prefer the
        // launcher's own explanation when it has one. The launcher knows whether the
        // engine failed to start (and why); a probe only knows that it is not
        // answering — naming one cause when the real one may be another is how
        // "The AI service is offline. Check the launcher." misleads.
        $launcher = $this->launcherEngineError($host);

        return [
            'online' => false,
            'model' => null,
            'message' => $launcher['message'] ?? "The AI service is offline at {$host}.",
            'detail' => $launcher['detail']
                ?? ($code > 0
                    ? "probe: {$modelsUrl} answered HTTP {$code}"
                    : "probe: no answer from {$modelsUrl}" . ($curlError !== '' ? " ({$curlError})" : '')),
            'probe' => $modelsUrl,
            'status' => (int) $code,
        ];
    }

    /**
     * The launcher's boot-time engine failure, if it published one. The launcher API
     * sits on the same host as the AI endpoint, on port 9876.
     *
     * @return array{message:string, detail:string}|null
     */
    private function launcherEngineError(string $aiHost): ?array
    {
        $base = preg_replace('#:\d{1,5}(/v1)?/?$#', ':9876', $aiHost);
        if (!is_string($base) || $base === '') {
            return null;
        }

        $ch = curl_init("{$base}/api/switch-status");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            return null;
        }
        $status = json_decode($response, true);
        $error = is_array($status) ? ($status['engine_error'] ?? null) : null;
        if (!is_array($error)) {
            return null;
        }
        $message = trim((string) ($error['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        return ['message' => $message, 'detail' => trim((string) ($error['detail'] ?? ''))];
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
