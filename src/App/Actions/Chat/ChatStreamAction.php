<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\ChatManager;
use App\Services\ModelBusyException;

class ChatStreamAction extends BaseAction
{
    public function __construct(
        private $db,
        private $agentManager
    ) {
    }

    public function execute(int $sessionId, string $query, $imageFile, $cacheAction, $cacheKey, $activeEditFile = null): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @set_time_limit(600);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $pad = str_repeat(" ", 4096);
        echo ":{$pad}\n\n";
        @flush();

        $chatManager = new ChatManager(
            $this->db,
            $this->agentManager
        );

        try {
            $chatManager->process($sessionId, $query, $imageFile, $activeEditFile, function ($event, $data) {
                $payload = json_encode(['event' => $event, 'data' => $data]);
                echo "data: {$payload}\n\n";
                @ob_flush();
                @flush();
            });
        } catch (ModelBusyException $e) {
            $payload = json_encode(['event' => 'error', 'data' => ['message' => $e->getMessage()]]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        }
    }
}
