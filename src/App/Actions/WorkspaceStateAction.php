<?php

namespace App\Actions;

use App\Cache;
use App\Database;
use App\HealthCheck;
use App\Repositories\ChatSessionRepository;
use App\Services\ModelLock;

/**
 * Small read-only UI responses. These intentionally run before the full page
 * bootstrap: navigation and availability do not need launcher model choices,
 * memory loading, schema initialisation, or job maintenance.
 */
final class WorkspaceStateAction
{
    public const ACTIONS = ['get_ai_availability', 'get_conversation', 'get_reply_rating'];

    public function execute(string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $bufferLevel = ob_get_level();
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                $result = ['status' => 'error', 'message' => 'This action is read-only.'];
            } elseif ($action === 'get_ai_availability') {
                $result = $this->availability();
            } elseif ($action === 'get_reply_rating') {
                $result = $this->rating();
            } else {
                $result = $this->conversation();
            }
        } catch (\Throwable $error) {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
            http_response_code(503);
            $result = ['status' => 'error', 'state' => 'unknown', 'message' => 'Unable to load workspace state. Please try again.'];
        }
        echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function availability(): array
    {
        $occupancy = ModelLock::availability();
        if ($occupancy['busy']) {
            return ['status' => 'success', 'state' => 'busy', 'message' => $occupancy['message']];
        }
        // Reuse the existing AI probe, without database/launcher checks.
        $cached = Cache::get('ui_ai_health');
        $health = $cached ? json_decode($cached, true) : null;
        if (!is_array($health)) {
            $health = (new HealthCheck())->checkAi();
            Cache::set('ui_ai_health', json_encode($health), 15);
        }
        // The lock may have changed while a health probe was in flight.
        $occupancy = ModelLock::availability();
        if ($occupancy['busy']) {
            return ['status' => 'success', 'state' => 'busy', 'message' => $occupancy['message']];
        }
        return ['status' => 'success', 'state' => $health['online'] ? 'ready' : 'offline',
            'message' => $health['online'] ? 'AI ready' : 'The AI service is offline. Check the launcher.',
            'model' => $health['model'] ?? null];
    }

    private function conversation(): array
    {
        $sessionId = filter_var($_GET['session_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($sessionId === false) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Invalid conversation.'];
        }
        $history = [];
        $session = null;
        if ($sessionId > 0) {
            $repository = new ChatSessionRepository(new Database());
            $session = $repository->getById($sessionId);
            if (!$session) {
                http_response_code(404);
                return ['status' => 'error', 'message' => 'This conversation no longer exists.'];
            }
            $history = $repository->getHistory($sessionId);
        }
        ob_start();
        include dirname(__DIR__, 2) . '/views/chat-history.php';
        $messages = ob_get_clean();
        ob_start();
        include dirname(__DIR__, 2) . '/views/context-items.php';
        $context = ob_get_clean();
        return ['status' => 'success', 'session_id' => $sessionId,
            'title' => $session['title'] ?? 'New conversation',
            'is_starred' => !empty($session['is_starred']),
            'tokens' => (int)($session['context_tokens'] ?? 0),
            'messages_html' => $messages, 'context_html' => $context];
    }

    private function rating(): array
    {
        $id = filter_var($_GET['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Invalid reply.'];
        }
        $rows = (new Database())->selectSafe('chat_history', ['id' => $id, 'role' => 'assistant']);
        if (!$rows) {
            http_response_code(404);
            return ['status' => 'error', 'message' => 'This reply no longer exists.'];
        }
        return ['status' => 'ok', 'rating' => ($rows[0]['rating'] ?? null) === null ? null : (int)$rows[0]['rating'],
            'reason' => $rows[0]['rating_reason'] ?? ''];
    }
}
