<?php

declare(strict_types=1);

namespace App\Services;

use App\Cache;
use Predis\Client;

/**
 * Messages the user sent while the engine was busy, held until something can answer them.
 *
 * Why a queue and not a column: the messages are persisted as rows the moment they arrive (so the bubbles survive a
 * reload and are visible immediately), and this list carries only what the answer turn needs — the row ids in arrival
 * order, so the batch can be assembled and those rows can be marked as picked up. The two must not be confused: the row
 * is the transcript's record, this is the work list.
 *
 * Held rows carry `message_type = 'queued'` and prompt assembly skips that type, so a discarded message can never be
 * answered later. That is the lever plan Task 9a chose: `active_context` exists but is honoured nowhere in assembly
 * (a held row marked `active_context = 0` would still have been sent to the model).
 *
 * **Taking a batch is one round trip.** `drain()` returns the entries and removes the list inside a single Lua script,
 * so two runners cannot both receive the same messages: whoever's script runs first gets them and the other gets an
 * empty list. `restore()` is the same shape in reverse, for a runner that took a batch and then could not answer it.
 *
 * No TTL. The list holds work that is owed — messages the user sent and has not been answered — and a list that quietly
 * expires is work that quietly disappears. If a batch is ever lost, the batch rows are still in the transcript, still
 * marked as held, and boot derives them again (QueueReconciliation).
 *
 * See `.hermes/plans/evidence/2026-10-11_durable-model-queue-rulings.md` (R17) and
 * `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
 */
final class PendingPromptQueue
{
    /** Return the whole list and delete it in one round trip: the atomic take. */
    private const TAKE_SCRIPT = <<<'LUA'
local items = redis.call('LRANGE', KEYS[1], 0, -1)
redis.call('DEL', KEYS[1])
return items
LUA;

    /** Push a deferred batch back to the front, keeping its arrival order. */
    private const RESTORE_SCRIPT = <<<'LUA'
for i = #ARGV, 1, -1 do
    redis.call('LPUSH', KEYS[1], ARGV[i])
end
return redis.call('LLEN', KEYS[1])
LUA;

    /** How many were held, then forget them. */
    private const DISCARD_SCRIPT = <<<'LUA'
local held = redis.call('LLEN', KEYS[1])
redis.call('DEL', KEYS[1])
return held
LUA;

    public function __construct(private string $prefix = 'pending_prompt')
    {
    }

    /** Record one held message (its persisted row id and its text), appended in arrival order. */
    public function append(int $sessionId, int $rowId, string $text): void
    {
        $key = $this->key($sessionId);
        $this->redis()->rpush($key, [json_encode(['row_id' => $rowId, 'text' => $text, 'at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        SessionState::publish($sessionId);
    }

    /**
     * The held messages for a conversation, in arrival order.
     *
     * @return array<int,array{row_id:int,text:string,at:string}>
     */
    public function all(int $sessionId): array
    {
        return $this->decode($this->redis()->lrange($this->key($sessionId), 0, -1));
    }

    /**
     * The stored shape, decoded. One implementation, because the take, a read and a restore must agree on it.
     *
     * @param array<int,mixed> $raw
     * @return array<int,array{row_id:int,text:string,at:string}>
     */
    private function decode(array $raw): array
    {
        $entries = [];
        foreach ($raw as $entry) {
            $decoded = json_decode((string)$entry, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entries[] = [
                'row_id' => (int)($decoded['row_id'] ?? 0),
                'text' => (string)($decoded['text'] ?? ''),
                'at' => (string)($decoded['at'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * Take the held messages and clear the list in ONE Redis round trip, so two runners cannot both answer the same
     * batch.
     *
     * @return array<int,array{row_id:int,text:string,at:string}>
     */
    public function drain(int $sessionId): array
    {
        $entries = $this->redis()->eval(self::TAKE_SCRIPT, 1, $this->key($sessionId));
        SessionState::publish($sessionId);

        return $this->decode(is_array($entries) ? $entries : []);
    }

    /**
     * Put a batch back, for a runner that took it and could not answer it after all.
     *
     * Pushed to the FRONT in arrival order: these entries are older than anything appended since the take, so the list
     * keeps its oldest-first contract and nothing is answered twice.
     *
     * @param array<int,array{row_id:int,text:string,at:string}> $entries
     */
    public function restore(int $sessionId, array $entries): void
    {
        $payload = [];
        foreach ($entries as $entry) {
            $payload[] = json_encode([
                'row_id' => (int)($entry['row_id'] ?? 0),
                'text' => (string)($entry['text'] ?? ''),
                'at' => (string)($entry['at'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($payload === []) {
            return;
        }

        $this->redis()->eval(
            self::RESTORE_SCRIPT,
            1,
            $this->key($sessionId),
            ...$payload
        );
        SessionState::publish($sessionId);
    }

    /** Forget the held messages without answering them. Returns how many were dropped. */
    public function discard(int $sessionId): int
    {
        $dropped = (int)$this->redis()->eval(self::DISCARD_SCRIPT, 1, $this->key($sessionId));
        SessionState::publish($sessionId);

        return $dropped;
    }

    public function size(int $sessionId): int
    {
        return (int)$this->redis()->llen($this->key($sessionId));
    }

    /**
     * The text a batch turn should carry: the held messages joined in arrival order, each trimmed, empties dropped.
     * Blank-line separated, because they are separate messages — the user wrote them one after another.
     *
     * @param array<int,array{row_id:int,text:string,at:string}> $entries
     */
    public static function joinText(array $entries): string
    {
        $parts = [];
        foreach ($entries as $entry) {
            $text = trim((string)($entry['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    private function key(int $sessionId): string
    {
        return $this->prefix . ':' . $sessionId;
    }

    private function redis(): Client
    {
        return Cache::getClient();
    }
}
