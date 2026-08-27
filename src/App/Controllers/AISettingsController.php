<?php

namespace App\Controllers;

use App\Enums\Action;
use App\Enums\Tab;
use App\Enums\ApiAction;
use App\Repositories\ChatSessionRepository;

class AISettingsController extends BaseController
{
    private $db;
    private $chatSessionRepository;
    private $envEditor;

    public function __construct($db, ChatSessionRepository $chatSessionRepository, $envEditor)
    {
        $this->db = $db;
        $this->chatSessionRepository = $chatSessionRepository;
        $this->envEditor = $envEditor;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }

    private function handlePost(): void
    {
        $sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
        $activeTab = Tab::tryFrom($_POST['tab'] ?? $_GET['tab'] ?? '') ?? Tab::CHATS;

        $action = $this->resolvePostAction();
        if ($action === Action::SAVE_SETTINGS) {
            $this->saveSettings($sessionId, $activeTab);
        } elseif ($action === Action::CLEAR_ALL) {
            $this->clearAllData();
        }
    }

    private function handleGet(): void
    {
        $apiAction = ApiAction::tryFrom($_GET['api_action'] ?? '');
        if ($apiAction === ApiAction::SYNC_LMSTUDIO_LIMIT) {
            $this->handleTokenLimit();
        } elseif ($apiAction === ApiAction::GET_SWITCH_STATUS) {
            $this->handleSwitchStatus();
        }
    }

    private function saveSettings(int $sessionId, Tab $activeTab): void
    {
        $currentEnv = $this->envEditor->read();
        $newEnv = [];

        foreach (array_keys($currentEnv) as $key) {
            if (isset($_POST[$key]) && !in_array($key, ['LLM_MODEL_ID', 'LLM_MODEL_NAME', 'LLM_CTX_SIZE'], true)) {
                $newEnv[$key] = $_POST[$key];
            }
        }

        $modelId = trim($_POST['model_id'] ?? '', '\"\' ');
        $ctxSize = (int)($_POST['ctx_size'] ?? 0);

        // Re-sync .env model keys with the model llama is actually running. A
        // switch that timed out mid-download leaves .env stale, which both shows
        // the wrong model in the UI and defeats change detection on the next save.
        $loaded = $this->getLoadedModel();
        if ($loaded !== null && !empty($loaded['id'])
            && (trim($currentEnv['LLM_MODEL_ID'] ?? '', '\"\' ') !== $loaded['id']
                || trim($currentEnv['LLM_MODEL_NAME'] ?? '', '\"\' ') !== $loaded['name'])) {
            $this->envEditor->write([
                'LLM_MODEL_ID'   => $loaded['id'],
                'LLM_MODEL_NAME' => $loaded['name'],
            ]);
            $currentEnv['LLM_MODEL_ID']   = $loaded['id'];
            $currentEnv['LLM_MODEL_NAME'] = $loaded['name'];
        }

        if (!$this->envEditor->write($newEnv)) {
            $this->respond($sessionId, $activeTab, ['status' => 'error', 'message' => 'Failed to write settings to .env.']);
            return;
        }

        $modelChanged = $modelId !== '' && $modelId !== trim($currentEnv['LLM_MODEL_ID'] ?? '', '\"\' ');
        $ctxChanged = $ctxSize > 0 && $ctxSize !== (int)($currentEnv['LLM_CTX_SIZE'] ?? 0);

        if ($modelChanged || $ctxChanged) {
            $this->respond($sessionId, $activeTab, $this->switchModel());
        } else {
            $this->respond($sessionId, $activeTab, ['status' => 'saved']);
        }
    }

    private function getLoadedModel(): ?array
    {
        $apiUrl = rtrim(\App\Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1'), '/');

        $ch = curl_init($apiUrl . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false || $response === '') {
            return null;
        }
        $data = json_decode($response, true);
        $loadedName = $data['data'][0]['id'] ?? null;
        if (!$loadedName) {
            return null;
        }

        $modelsUrl = $this->goApiBase('api/models');

        $ch = curl_init($modelsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false || $response === '') {
            return null;
        }
        foreach (json_decode($response, true) ?: [] as $m) {
            if (($m['name'] ?? '') === $loadedName) {
                return ['id' => $m['model_id'] ?? null, 'name' => $loadedName];
            }
        }
        return null;
    }

    private function clearAllData(): void
    {
        if ($this->db) {
            $this->chatSessionRepository->truncateAll();
        }
        $this->redirect("index.php?new_chat=1");
    }

    private function switchModel(): array
    {
        $modelId = trim($_POST['model_id'] ?? '', '\"\' ');
        $ctxSize = (int)($_POST['ctx_size'] ?? 0);

        if ($modelId === '') {
            return ['status' => 'saved'];
        }

        $payload = json_encode([
            'model_id' => $modelId,
            'ctx_size' => max($ctxSize, 512),
        ]);

        $ch = curl_init($this->goApiBase('api/model-switch'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            return ['status' => 'error', 'message' => 'Launcher did not respond: ' . ($curlErr ?: 'empty response')];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode === 202 || ($httpCode === 200 && ($data['status'] ?? '') === 'ok')) {
            return [
                'status'   => 'switching',
                'model_id' => $modelId,
                'name'     => $data['name'] ?? $modelId,
                'ctx_size' => $ctxSize,
            ];
        }

        if ($httpCode === 409) {
            return [
                'status'   => 'busy',
                'model_id' => $data['model_id'] ?? $modelId,
                'name'     => $data['name'] ?? $modelId,
                'stage'    => $data['stage'] ?? 'downloading',
                'progress' => $data['progress'] ?? 0,
            ];
        }

        return ['status' => 'error', 'message' => $data['error'] ?? ('Model switch failed (HTTP ' . $httpCode . ')')];
    }

    private function goApiBase(string $endpoint): string
    {
        $apiUrl = \App\Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1');
        $host = str_replace('/v1', '', rtrim($apiUrl, '/'));
        $base = preg_replace('#:\d{1,5}/?$#', ':9876/' . ltrim($endpoint, '/'), $host);
        if ($base === null || $base === '' || $base === $host) {
            $base = 'http://host.docker.internal:9876/' . ltrim($endpoint, '/');
        }
        return $base;
    }

    private function respond(int $sessionId, Tab $activeTab, array $result): void
    {
        if ($this->isApiRequest()) {
            $this->jsonResponse($result);
        } else {
            $this->redirect($this->buildUrl($sessionId, $activeTab));
        }
    }

    private function handleSwitchStatus(): void
    {
        $ch = curl_init($this->goApiBase('api/switch-status'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === '') {
            $this->jsonResponse(['active' => false, 'stage' => 'error', 'error' => 'switch status unavailable'], 502);
            return;
        }

        $status = json_decode($response, true) ?: [];

        // Persist the model identity to the PHP .env once the switch lands,
        // so the UI reflects the new model after the page reloads.
        if (empty($status['active']) && ($status['stage'] ?? '') === 'loaded' && !empty($status['model_id'])) {
            $envUpdates = [
                'LLM_MODEL_ID'   => $status['model_id'],
                'LLM_MODEL_NAME' => $status['name'] ?? $status['model_id'],
            ];
            if (!empty($status['ctx_size'])) {
                $envUpdates['LLM_CTX_SIZE'] = (string)$status['ctx_size'];
            }
            $this->envEditor->write($envUpdates);

            // The health status is cached in Redis for 10s, and during the switch
            // the poll requests above kept caching an "offline" snapshot while
            // llama.cpp was restarting. Bust that cache so the imminent page reload
            // re-checks against the now-ready server instead of showing stale
            // "offline" until the TTL expires.
            try {
                \App\Cache::delete('system_health_status');
            } catch (\Exception $e) {
            }
        }

        $this->jsonResponse($status);
    }

    private function handleTokenLimit(): void
    {
        $env = $this->envEditor->read();
        $url = $env['LLM_API_URL'] ?? 'http://localhost:1234/v1';

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 1234;
        $baseUrl = "{$scheme}://{$host}:{$port}";

        $detectedLimit = null;
        $modelName = '';

        $ch = curl_init("{$baseUrl}/props");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            
            if (isset($data['default_generation_settings']['n_ctx'])) {
                $detectedLimit = $data['default_generation_settings']['n_ctx'];
                
                $modelName = $data['model_alias'] ?? $data['model_path'] ?? 'Llama.cpp Model';
            }
        }

        if (!$detectedLimit) {
            $endpoints = ["/api/v0/models", "/api/v1/models"];
            foreach ($endpoints as $endpoint) {
                $ch = curl_init("{$baseUrl}{$endpoint}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && !empty($response)) {
                    $data = json_decode($response, true);
                    if (isset($data['data']) && is_array($data['data'])) {
                        foreach ($data['data'] as $model) {
                            if (isset($model['state']) && $model['state'] === 'loaded') {
                                $detectedLimit = $model['loaded_context_length'] ?? $model['max_context_length'] ?? null;
                                $modelName = $model['id'] ?? '';
                                if ($detectedLimit) {
                                    break 2; 
                                }
                            }
                        }

                        if (!$detectedLimit && !empty($data['data'])) {
                            $firstModel = $data['data'][0];
                            $detectedLimit = $firstModel['max_context_length'] ?? $firstModel['loaded_context_length'] ?? null;
                            $modelName = $firstModel['id'] ?? '';
                            break;
                        }
                    }
                }
            }
        }

        if (!$detectedLimit) {
            $this->jsonResponse([
                'status' => 'error', 
                'message' => "Could not retrieve context limit metadata from the loaded model {$baseUrl}."
            ], 404);
            return;
        }

        $newEnv = ['LLM_CTX_SIZE' => (int)$detectedLimit];
        $writeSuccess = $this->envEditor->write($newEnv);

        if (!$writeSuccess) {
            $this->jsonResponse([
                'status' => 'error', 
                'message' => 'Failed to write updated token limit to .env file.'
            ], 500);
            return;
        }

        $this->jsonResponse([
            'status' => 'success',
            'model' => $modelName,
            'limit' => (int)$detectedLimit
        ]);
    }

    private function resolvePostAction(): ?Action
    {
        $actionValue = $_POST['action'] ?? null;
        if ($actionValue !== null) {
            return Action::tryFrom($actionValue);
        }

        foreach (Action::cases() as $action) {
            if (isset($_POST[$action->value])) {
                return $action;
            }
        }

        return null;
    }
}
