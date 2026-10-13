<?php

declare(strict_types=1);

namespace App\Tests;

use App\Actions\Chat\StopTurnAction;
use App\Cache;
use App\Database;
use App\Services\PendingPromptQueue;
use App\Services\PromptAssemblyService;

/**
 * Deterministic (no LLM, no engine) coverage for the held-message path and the stop control:
 *
 *   - the pending set keeps arrival order, drains atomically, and discards without answering;
 *   - a batch's prompt text is the messages joined in order, trimmed, one blank line apart;
 *   - a held row (`message_type = 'queued'`) is invisible to prompt assembly, while the same row as an ordinary
 *     message is not — this is the guarantee that a stopped message can never be answered later;
 *   - the stop control drops the held messages, flips their rows to `stopped` (so the transcript can still show what
 *     was attempted) and sets the turn-stop flag the running turn reads.
 *
 * The stop action is driven directly with $_POST, which is how the router drives it.
 */
class PendingPromptQueueTest
{
    private Database $db;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        $this->db->initTables();

        $this->runAppendOrderAndSize();
        $this->runDrainIsAtomic();
        $this->runRestoreKeepsOrder();
        $this->runDiscardWithoutAnswering();
        $this->runJoinText();
        $this->runHeldRowsAreInvisibleToAssembly();
        $this->runStopDropsHeldMessagesAndFlagsTheTurn();
        $this->runStopNoticeWithNothingRunning();
        $this->runStopNoticeLeftToTheTurnInFlight();
        $this->runStopFlagAndNoticeText();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);
        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    private function runAppendOrderAndSize(): void
    {
        echo "\n=== held messages keep arrival order ===\n";
        $sessionId = 990001;
        $queue = new PendingPromptQueue('pending_prompt_test');
        $this->forget($queue, $sessionId);

        $queue->append($sessionId, 11, 'first');
        $queue->append($sessionId, 12, 'second');
        $queue->append($sessionId, 13, 'third');

        $all = $queue->all($sessionId);
        $this->testEq('three held', 3, count($all));
        $this->testEq('arrival order kept', ['first', 'second', 'third'], array_column($all, 'text'));
        $this->testEq('row ids kept', [11, 12, 13], array_column($all, 'row_id'));
        $this->testEq('size agrees', 3, $queue->size($sessionId));
        // No expiry on held work: a list that quietly expires is work that quietly disappears (task 7). The rows are the
        // record; the list is the index onto them, and the index never drops an entry on its own.
        $this->testEq('the list carries no TTL', -1, Cache::getClient()->ttl('pending_prompt_test:' . $sessionId));

        $this->forget($queue, $sessionId);
    }

    private function runDrainIsAtomic(): void
    {
        echo "\n=== draining takes the batch and clears it ===\n";
        $sessionId = 990002;
        $queue = new PendingPromptQueue('pending_prompt_test');
        $this->forget($queue, $sessionId);

        $queue->append($sessionId, 21, 'a');
        $queue->append($sessionId, 22, 'b');

        $drained = $queue->drain($sessionId);
        $this->testEq('both came back', 2, count($drained));
        $this->testEq('and the list is empty', 0, $queue->size($sessionId));
        $this->testEq('a second drain returns nothing', 0, count($queue->drain($sessionId)));

        $this->forget($queue, $sessionId);
    }

    private function runRestoreKeepsOrder(): void
    {
        echo "\n=== a deferred batch goes back in front, in order ===\n";
        $sessionId = 990004;
        $queue = new PendingPromptQueue('pending_prompt_test');
        $this->forget($queue, $sessionId);

        $queue->append($sessionId, 41, 'oldest');
        $queue->append($sessionId, 42, 'second');
        $taken = $queue->drain($sessionId);

        // The user sends again while the runner that took the batch is finding out it cannot answer it.
        $queue->append($sessionId, 43, 'newest');
        $queue->restore($sessionId, $taken);

        $this->testEq('the batch is back, oldest first, in front of the newer message',
            [41, 42, 43], array_map(static fn(array $e): int => (int)$e['row_id'], $queue->all($sessionId)));
        $this->testEq('and restoring it still sets no expiry',
            -1, Cache::getClient()->ttl('pending_prompt_test:' . $sessionId));
        $this->testEq('restoring nothing changes nothing', 3, $queue->size($sessionId));

        $queue->restore($sessionId, []);
        $this->testEq('an empty restore is a no-op', 3, $queue->size($sessionId));

        $this->forget($queue, $sessionId);
    }

    private function runDiscardWithoutAnswering(): void
    {
        echo "\n=== discarding forgets without answering ===\n";
        $sessionId = 990003;
        $queue = new PendingPromptQueue('pending_prompt_test');
        $this->forget($queue, $sessionId);

        $queue->append($sessionId, 31, 'please ignore');
        $queue->append($sessionId, 32, 'and this');
        $this->testEq('reports how many were dropped', 2, $queue->discard($sessionId));
        $this->testEq('nothing left', 0, $queue->size($sessionId));
        $this->testEq('nothing to drain', [], $queue->drain($sessionId));

        $this->forget($queue, $sessionId);
    }

    private function runJoinText(): void
    {
        echo "\n=== a batch reads as its messages, in order ===\n";
        $entries = [
            ['row_id' => 1, 'text' => '  what did guardian argue?  ', 'at' => ''],
            ['row_id' => 2, 'text' => '', 'at' => ''],
            ['row_id' => 3, 'text' => 'nvm, i answered it myself', 'at' => ''],
        ];
        $this->testEq('trimmed, empties dropped, one blank line apart',
            "what did guardian argue?\n\nnvm, i answered it myself", PendingPromptQueue::joinText($entries));
        $this->testEq('a single message is unchanged',
            'just this', PendingPromptQueue::joinText([['row_id' => 1, 'text' => 'just this', 'at' => '']]));
        $this->testEq('nothing held, nothing to say', '', PendingPromptQueue::joinText([]));
    }

    private function runHeldRowsAreInvisibleToAssembly(): void
    {
        echo "\n=== a held row never reaches the model ===\n";
        $sessionId = $this->seedSession();
        try {
            $queuedRowId = $this->seedRow($sessionId, 'user', 'HELD-MESSAGE-TEXT', 'queued');
            $normalRowId = $this->seedRow($sessionId, 'user', 'NORMAL-MESSAGE-TEXT', 'text');
            $assistantRowId = $this->seedRow($sessionId, 'assistant', 'ASSISTANT-REPLY', 'text');

            $service = new PromptAssemblyService($this->db, '/tmp');
            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $built = json_encode($service->buildMessagesArray('system prompt', $history), JSON_UNESCAPED_UNICODE);

            $this->test('a held message is not sent to the model', !str_contains($built, 'HELD-MESSAGE-TEXT'));
            $this->test('an ordinary message still is', str_contains($built, 'NORMAL-MESSAGE-TEXT'));
            $this->test('and so is the reply', str_contains($built, 'ASSISTANT-REPLY'));

            // The contrast that makes the rule meaningful: the same row as an ordinary message IS sent.
            $this->db->update('chat_history', ['message_type' => 'text'], ['id' => $queuedRowId]);
            $history = $this->db->selectSafe('chat_history', ['session_id' => $sessionId]);
            $built = json_encode($service->buildMessagesArray('system prompt', $history), JSON_UNESCAPED_UNICODE);
            $this->test('the same row as an ordinary message is sent', str_contains($built, 'HELD-MESSAGE-TEXT'));

            $this->test('sanity: the assistant row exists', $assistantRowId > 0 && $normalRowId > 0);
        } finally {
            $this->cleanup($sessionId);
        }
    }

    private function runStopDropsHeldMessagesAndFlagsTheTurn(): void
    {
        echo "\n=== stop drops the held messages and flags the turn ===\n";
        $sessionId = $this->seedSession();
        $queue = new PendingPromptQueue();
        try {
            $first = $this->seedRow($sessionId, 'user', 'first held', 'queued');
            $second = $this->seedRow($sessionId, 'user', 'second held', 'queued');
            $queue->append($sessionId, $first, 'first held');
            $queue->append($sessionId, $second, 'second held');

            $_POST = ['session_id' => (string)$sessionId];
            // With a turn in flight, the flag is left for it to consume (the other branch — nothing running — is
            // covered by runStopNoticeWithNothingRunning()).
            Cache::set('turn_running:' . $sessionId, '1', 60);
            // Driven through `perform()`, not `execute()`: the action's JSON response calls exit(), which would end
            // the whole test process (the router still drives execute() in production).
            $result = (new StopTurnAction($this->db))->perform($sessionId);
            $_POST = [];

            $this->testEq('the held list is empty afterwards', 0, $queue->size($sessionId));
            $this->testEq('the row is marked stopped, not deleted', 'stopped', (string)($this->row($first)['message_type'] ?? ''));
            $this->testEq('the second row too', 'stopped', (string)($this->row($second)['message_type'] ?? ''));
            $this->testEq('the turn-stop flag is set for the running turn', '1', (string)Cache::get('turn_stop:' . $sessionId));
            $this->testEq('it reports both dropped', 2, (int)($result['dropped'] ?? 0));

            // A stopped row is still invisible to the model: stop must not leave a prompt waiting to be answered.
            $service = new PromptAssemblyService($this->db, '/tmp');
            $built = json_encode($service->buildMessagesArray('system prompt', $this->db->selectSafe('chat_history', ['session_id' => $sessionId])), JSON_UNESCAPED_UNICODE);
            $this->test('a stopped message never reaches the model', !str_contains($built, 'first held') && !str_contains($built, 'second held'));
        } finally {
            Cache::delete('turn_stop:' . $sessionId);
            Cache::delete('turn_running:' . $sessionId);
            $queue->discard($sessionId);
            $this->cleanup($sessionId);
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function runStopNoticeWithNothingRunning(): void
    {
        echo "\n=== stop with nothing running writes the notice itself ===\n";
        $sessionId = $this->seedSession();
        $queue = new PendingPromptQueue();
        try {
            Cache::delete('turn_running:' . $sessionId);
            $before = $this->assistantNotices($sessionId);

            (new StopTurnAction($this->db))->perform($sessionId);

            $this->testEq('exactly one notice row written', $before + 1, $this->assistantNotices($sessionId));
            $this->testEq('and it is the owner\'s line', \App\ChatManager::STOP_NOTICE, $this->lastAssistantMessage($sessionId));
            $this->testEq('the flag is cleared, so the next turn is not stopped', null, Cache::get('turn_stop:' . $sessionId));
        } finally {
            $queue->discard($sessionId);
            $this->cleanup($sessionId);
        }
    }

    private function runStopNoticeLeftToTheTurnInFlight(): void
    {
        echo "\n=== a stop while a turn is running leaves the notice to that turn ===\n";
        $sessionId = $this->seedSession();
        $queue = new PendingPromptQueue();
        try {
            Cache::set('turn_running:' . $sessionId, '1', 60);
            $before = $this->assistantNotices($sessionId);

            (new StopTurnAction($this->db))->perform($sessionId);

            $this->testEq('no notice row written here', $before, $this->assistantNotices($sessionId));
            $this->testEq('the flag is left for the turn to consume', '1', (string)Cache::get('turn_stop:' . $sessionId));
        } finally {
            Cache::delete('turn_running:' . $sessionId);
            Cache::delete('turn_stop:' . $sessionId);
            $queue->discard($sessionId);
            $this->cleanup($sessionId);
        }
    }

    private function runStopFlagAndNoticeText(): void
    {
        echo "\n=== reading the flag consumes it, and the line reads right ===\n";
        $sessionId = $this->seedSession();
        try {
            $manager = new \App\ChatManager($this->db, new \App\AgentManager(), fn(string $s): int => (int)ceil(mb_strlen($s) / 4));
            $stopRequested = new \ReflectionMethod(\App\ChatManager::class, 'stopRequested');
            $stopRequested->setAccessible(true);
            $withNotice = new \ReflectionMethod(\App\ChatManager::class, 'withStopNotice');
            $withNotice->setAccessible(true);

            Cache::delete('turn_stop:' . $sessionId);
            $this->testEq('no flag means no stop', false, $stopRequested->invoke($manager, $sessionId));

            Cache::set('turn_stop:' . $sessionId, '1', 60);
            $this->testEq('the flag is seen', true, $stopRequested->invoke($manager, $sessionId));
            $this->testEq('and consumed, so it cannot stop a second turn', false, $stopRequested->invoke($manager, $sessionId));

            $this->testEq('a partial answer keeps its text', "half a sentence\n\n" . \App\ChatManager::STOP_NOTICE, $withNotice->invoke(null, 'half a sentence'));
            $this->testEq('trailing whitespace is trimmed', "done\n\n" . \App\ChatManager::STOP_NOTICE, $withNotice->invoke(null, "done\n\n  "));
            $this->testEq('nothing written means the line alone', \App\ChatManager::STOP_NOTICE, $withNotice->invoke(null, ''));
        } finally {
            Cache::delete('turn_stop:' . $sessionId);
            $this->cleanup($sessionId);
        }
    }

    private function assistantNotices(int $sessionId): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) c FROM chat_history WHERE session_id = ? AND role = \'assistant\' AND message = ?',
            [$sessionId, \App\ChatManager::STOP_NOTICE]
        );

        return (int)($rows[0]['c'] ?? 0);
    }

    private function lastAssistantMessage(int $sessionId): string
    {
        $rows = $this->db->query(
            'SELECT message FROM chat_history WHERE session_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 1',
            [$sessionId]
        );

        return (string)($rows[0]['message'] ?? '');
    }

    private function forget(PendingPromptQueue $queue, int $sessionId): void
    {
        $queue->discard($sessionId);
    }

    private function seedSession(): int
    {
        $this->db->insert('chat_sessions', ['title' => 'pending-prompt-test', 'context_tokens' => 0]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function seedRow(int $sessionId, string $role, string $message, string $messageType): int
    {
        $this->db->insert('chat_history', [
            'session_id' => $sessionId,
            'role' => $role,
            'message' => $message,
            'message_type' => $messageType,
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    private function row(int $rowId): array
    {
        return $this->db->selectSafe('chat_history', ['id' => $rowId])[0] ?? [];
    }

    private function cleanup(int $sessionId): void
    {
        $this->db->query('DELETE FROM chat_history WHERE session_id = ?', [$sessionId]);
        $this->db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }
}
