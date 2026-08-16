<?php

namespace App\Bootstrap;

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;

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

        if ($db) {
            $sessions = $chatSessionRepository->getAllDesc();
            foreach ($sessions as $s) {
                if ((int)$s['id'] === $sessionId) {
                    $activeSessionTitle = $s['title'];
                    $totalSessionTokens = (int)($s['context_tokens'] ?? 0);
                    break;
                }
            }

            $history = $chatSessionRepository->getHistory($sessionId);

            try {
                $memories = $memoryRepository->getAllLimit500();
                $memoryCount = count($memories);
            } catch (\Exception $e) {
            }

            try {
                (new \App\Jobs\JobRepository($db))->deleteStaleUnread();
                (new \App\Jobs\JobRunRepository($db))->cancelOrphanedRuns();
            } catch (\Throwable $e) {
            }
        }

        return compact(
            'sessions',
            'activeSessionTitle',
            'history',
            'totalSessionTokens',
            'memories',
            'memoryCount',
        );
    }
}
