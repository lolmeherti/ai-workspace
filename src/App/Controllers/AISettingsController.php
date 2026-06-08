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
        }
    }

    private function saveSettings(int $sessionId, Tab $activeTab): void
    {
        $currentEnv = $this->envEditor->read();
        $newEnv = [];

        foreach (array_keys($currentEnv) as $key) {
            if (isset($_POST[$key])) {
                $newEnv[$key] = $_POST[$key];
            }
        }

        $this->envEditor->write($newEnv);
        $this->redirect($this->buildUrl($sessionId, $activeTab));
    }

    private function clearAllData(): void
    {
        if ($this->db) {
            $this->chatSessionRepository->truncateAll();
        }
        $this->redirect("index.php?new_chat=1");
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

        $newEnv = ['MEMORY_EXTRACTION_THRESHOLD_TOKENS' => (int)$detectedLimit];
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