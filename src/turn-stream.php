<?php

/*
 * The read side of a running turn.
 *
 *   GET /turn-stream.php?session=NNN
 *
 * The runner publishes its typed events into a Redis stream (App\Services\TurnStream); this endpoint relays them as
 * SSE. The SSE `id:` is the stream id, so a browser that reconnects resumes from its own `Last-Event-ID` exactly where
 * the viewer stopped — no bespoke reconnect logic, no missed chunks.
 *
 * Two fallbacks, both deliberate:
 *   - no stream yet and the conversation's newest user row is not closed → the turn has not started publishing; the
 *     endpoint keeps the connection open and says nothing (the viewer waits).
 *   - no stream and the newest user row IS closed → the turn already finished; the stored answer is sent once as
 *     `done`, so a viewer that arrives late is served by the row rather than by a stream that no longer exists.
 *
 * Nothing here writes to the transcript. The stream is transport; `chat_history` is the record.
 */

ini_set('display_errors', (($_GET['diag'] ?? '') === '1') ? '1' : '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Services\TurnStream;

Config::load(__DIR__);

$sessionId = (int)($_GET['session'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(400);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}

$db = new Database();
$stream = new TurnStream();

$from = (string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['from'] ?? '0'));
$deadline = time() + (int)(getenv('TURN_STREAM_MAX_SECONDS') ?: 180);

/** `?diag=1` narrates what the relay is doing as SSE comments — transport debugging without a client to inspect. */
$diag = (string)($_GET['diag'] ?? '') === '1';

/** One SSE frame. `id:` is what the browser echoes back on reconnect. */
$emitEvent = static function (string $id, string $event, array $data): void {
    if ($id !== '') {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
};

/**
 * The answer for the conversation's newest user row, when that row is already closed. Null while a turn is still owed,
 * which is what keeps a late viewer from being handed the *previous* answer as if it were this turn's.
 */
$storedAnswer = static function (Database $db, int $sessionId): ?array {
    $newest = $db->query(
        "SELECT u.id AS user_row_id, t.terminal_row_id
           FROM chat_history u
      LEFT JOIN turn_answers t ON t.user_row_id = u.id
          WHERE u.session_id = ?
            AND u.role = 'user'
            AND (u.message_type IS NULL OR u.message_type = 'text')
       ORDER BY u.id DESC LIMIT 1",
        [$sessionId]
    );
    $terminalRowId = (int)($newest[0]['terminal_row_id'] ?? 0);
    if ($terminalRowId <= 0) {
        return null;
    }

    $rows = $db->query(
        "SELECT id, message, source_map, perf_metrics, had_tool_calls
           FROM chat_history WHERE id = ?",
        [$terminalRowId]
    );
    if ($rows === []) {
        return null;
    }
    $row = $rows[0];
    $session = $db->query("SELECT title FROM chat_sessions WHERE id = ?", [$sessionId]);

    return [
        'message' => (string)$row['message'],
        'title' => (string)($session[0]['title'] ?? ''),
        'session_id' => $sessionId,
        'message_id' => (int)$row['id'],
        'had_tool_calls' => (bool)$row['had_tool_calls'],
        'sources' => json_decode((string)($row['source_map'] ?? '[]'), true) ?: [],
        'perf_metrics' => json_decode((string)($row['perf_metrics'] ?? 'null'), true),
        'from_stored_row' => true,
    ];
};

echo "retry: 1000\n\n";
@ob_flush();
@flush();

$lastKeepAlive = 0.0;
$iteration = 0;
while (time() < $deadline) {
    $iteration++;
    if (connection_aborted()) {
        if ($diag) {
            echo ": diag iter={$iteration} ABORTED\n\n";
            @ob_flush();
            @flush();
        }
        exit;
    }

    // A relay that dies silently leaves a viewer staring at nothing, so a failure here is reported on the stream itself
    // before the connection ends.
    try {
        $entries = $stream->read($sessionId, $from, 200);
        $hasStream = $stream->exists($sessionId);
    } catch (\Throwable $e) {
        $emitEvent('', 'error', ['message' => 'Turn stream unavailable: ' . $e->getMessage()]);
        exit;
    }

    if ($diag) {
        echo ': diag iter=' . $iteration . ' entries=' . count($entries) . ' has=' . ($hasStream ? 1 : 0) . ' from=' . $from . "\n\n";
        @ob_flush();
        @flush();
    }

    if ($entries === []) {
        if (!$hasStream) {
            $stored = $storedAnswer($db, $sessionId);
            if ($stored !== null) {
                $emitEvent('', 'done', $stored);
                exit;
            }
            if (microtime(true) - $lastKeepAlive > 2.0) {
                $lastKeepAlive = microtime(true);
                echo ": waiting for the turn to start\n\n";
                @ob_flush();
                @flush();
            }
            usleep(200_000);
            continue;
        }

        if (microtime(true) - $lastKeepAlive > 2.0) {
            $lastKeepAlive = microtime(true);
            echo ": turn still running\n\n";
            @ob_flush();
            @flush();
        }
        usleep(200_000);
        continue;
    }

    foreach ($entries as $entry) {
        $from = $entry['id'];
        $emitEvent($entry['id'], $entry['event'], $entry['data']);
        if ($entry['event'] === 'done') {
            $stream->finish($sessionId);
            exit;
        }
    }
}
