<?php

namespace App\Bootstrap;

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Cache;

class PageDataLoader
{
    public function load(
        $db,
        ChatSessionRepository $chatSessionRepository,
        MemoryRepository $memoryRepository,
        int $sessionId,
        $status
    ): array {
        $sessions = [];
        $activeSessionTitle = 'New Conversation';
        $history = [];
        $totalSessionTokens = 0;
        $memories = [];
        $memoryCount = 0;
        $queries = [];

        if ($db) {
            $sessions = $chatSessionRepository->getAllDesc();
            foreach ($sessions as $s) {
                if ((int)$s['id'] === $sessionId) {
                    $activeSessionTitle = $s['title'];
                    break;
                }
            }

            $history = $chatSessionRepository->getHistory($sessionId);
            foreach ($history as $msg) {
                $totalSessionTokens += (int)($msg['token_estimate'] ?? 0);
            }

            try {
                $memories = $memoryRepository->getAllLimit500();
                $memoryCount = count($memories);
            } catch (\Exception $e) {
            }
        }

        if ($status->redis) {
            try {
                $queries = Cache::getSearchLedger();
            } catch (\Exception $e) {
            }
        }

        return compact(
            'sessions',
            'activeSessionTitle',
            'history',
            'totalSessionTokens',
            'memories',
            'memoryCount',
            'queries'
        );
    }
}
