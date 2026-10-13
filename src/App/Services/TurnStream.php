<?php

declare(strict_types=1);

namespace App\Services;

use App\Cache;
use Predis\Client;

/**
 * A running turn's output as a stream a browser can subscribe to.
 *
 * The runner — today the request that accepted the message, from build task 4 the worker — publishes every typed event
 * it already streams (`reasoning`, `token`, `status`, `trace`, `sources`, `done`) into a per-session Redis stream. Any
 * viewer follows it through `src/turn-stream.php`, which relays the stream as SSE with the stream id as the SSE `id:`,
 * so the browser's own `Last-Event-ID` resumes exactly where it stopped and no bespoke reconnect logic exists.
 *
 * The stream is the transport, never the record: the answer lands in `chat_history`, and a viewer that arrives after
 * the turn finished is served from that row instead.
 *
 * See `.hermes/plans/evidence/2026-10-11_durable-model-queue-rulings.md` (R18).
 */
final class TurnStream
{
    /** Bound on one turn's tail. A long answer is thousands of chunks; a viewer that far behind reads the row instead. */
    public const MAX_EVENTS = 4000;

    /** A finished turn's stream lingers briefly, so a tab reconnecting right after it ends can replay the tail. */
    public const FINISHED_TTL_SECONDS = 120;

    public function __construct(private string $prefix = 'turn_stream')
    {
    }

    public function key(int $sessionId): string
    {
        return $this->prefix . ':' . $sessionId;
    }

    /** Start a fresh tail: the previous turn's events must never be replayed as this one's. */
    public function reset(int $sessionId): void
    {
        $this->redis()->del($this->key($sessionId));
    }

    /** Publish one event and return the stream id, which is what a viewer resumes from. */
    public function publish(int $sessionId, string $event, array $data = []): string
    {
        $id = $this->redis()->xadd(
            $this->key($sessionId),
            [
                'event' => $event,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            '*',
            ['trim' => ['MAXLEN', '~', self::MAX_EVENTS]]
        );

        return (string)$id;
    }

    /** The turn is over: keep the tail a moment for a reconnecting viewer, then let it expire. */
    public function finish(int $sessionId): void
    {
        $this->redis()->expire($this->key($sessionId), self::FINISHED_TTL_SECONDS);
    }

    public function exists(int $sessionId): bool
    {
        return (bool)$this->redis()->exists($this->key($sessionId));
    }

    public function lastId(int $sessionId): ?string
    {
        $entries = $this->redis()->xrevrange($this->key($sessionId), '+', '-', 1);
        if (!is_array($entries) || $entries === []) {
            return null;
        }
        $id = array_key_first($entries);

        return $id === null ? null : (string)$id;
    }

    /**
     * Events after $fromId ('0' from the beginning, '$' only new ones).
     *
     * @return array<int,array{id:string,event:string,data:array<string,mixed>}>
     */
    public function read(int $sessionId, string $fromId = '0', int $count = 200, int $blockMs = 0): array
    {
        $from = $fromId === '' ? '0' : $fromId;
        $raw = $this->redis()->xread($count, $blockMs > 0 ? $blockMs : null, [$this->key($sessionId)], $from);

        return $this->parse($raw);
    }

    /**
     * Flatten the XREAD answer. Predis keys it by stream name, each value a list of `[id, [field, value, …]]` entries.
     *
     * @return array<int,array{id:string,event:string,data:array<string,mixed>}>
     */
    private function parse(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $fields = is_array($entry[1] ?? null) ? $entry[1] : [];
                $map = [];
                for ($i = 0; $i + 1 < count($fields); $i += 2) {
                    $map[(string)$fields[$i]] = $fields[$i + 1];
                }
                $out[] = [
                    'id' => (string)($entry[0] ?? ''),
                    'event' => (string)($map['event'] ?? ''),
                    'data' => json_decode((string)($map['data'] ?? '{}'), true) ?: [],
                ];
            }
        }

        return $out;
    }

    private function redis(): Client
    {
        return Cache::getClient();
    }
}
