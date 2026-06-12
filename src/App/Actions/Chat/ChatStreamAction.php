<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\ChatManager;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\MemorySelector;

class ChatStreamAction extends BaseAction
{
    public function __construct(
        private $db,
        private $agentManager,
        private $memoryExtractor
    ) {
    }

    public function execute(int $sessionId, string $query, $imageFile, $cacheAction, $cacheKey): void
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

        $searchDecider = new SearchDecider($this->agentManager);
        $cacheEvaluator = new SemanticCacheEvaluator($this->agentManager);
        $contextCondenser = new ContextCondenser($this->agentManager);
        $memorySelectorInstance = $this->db ? new MemorySelector($this->db, $this->agentManager) : null;

        $chatManager = new ChatManager(
            $this->db,
            $this->agentManager,
            $this->memoryExtractor,
            $memorySelectorInstance,
            $searchDecider,
            $cacheEvaluator,
            $contextCondenser
        );

        $chatManager->process($sessionId, $query, $imageFile, $cacheAction, $cacheKey, function ($event, $data) {
            $payload = json_encode(['event' => $event, 'data' => $data]);
            echo "data: {$payload}\n\n";
            @ob_flush();
            @flush();
        });
    }
}
