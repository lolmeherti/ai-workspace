<?php

namespace App\Controllers;

use App\Cache;
use App\Enums\Action;
use App\Enums\Tab;
use App\Enums\ApiAction;

class CacheController extends BaseController
{
    private $status;

    public function __construct($status)
    {
        $this->status = $status;
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
        $action = $this->resolvePostAction();
        $sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);

        if ($action === Action::DELETE_QUERY) {
            $this->deleteQuery($sessionId);
        }
    }

    private function handleGet(): void
    {
        $apiAction = ApiAction::tryFrom($_GET['api_action'] ?? '');
        if ($apiAction === ApiAction::GET_CACHE) {
            $this->handleGetCache();
        }
    }

    private function handleGetCache(): void
    {
        if (!$this->status->redis) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Redis is offline.'], 503);
            return;
        }

        $key = $_GET['key'] ?? '';
        if (empty($key)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing cache key.'], 400);
            return;
        }

        try {
            $value = Cache::get($key);
            $decoded = json_decode($value, true);
            $isJson = (json_last_error() === JSON_ERROR_NONE);

            $this->jsonResponse([
                'status' => 'success',
                'key' => $key,
                'value' => $value,
                'is_json' => $isJson,
                'decoded' => $decoded
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function deleteQuery(int $sessionId): void
    {
        if (!$this->status->redis) {
            $this->redirect($this->buildUrl($sessionId, Tab::QUERIES));
            return;
        }

        $cacheKey = $_POST['cache_key'] ?? '';
        if (!empty($cacheKey)) {
            $ledger = Cache::getSearchLedger();
            $filteredLedger = array_filter($ledger, fn($item) => $item['cache_key'] !== $cacheKey);
            Cache::set('search_ledger', json_encode(array_values($filteredLedger)), 604800);
            Cache::delete($cacheKey);
        }
        $this->redirect($this->buildUrl($sessionId, Tab::QUERIES));
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