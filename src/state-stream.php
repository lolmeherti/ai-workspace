<?php

/**
 * The state stream: the app's own background state, pushed to whichever pages are open.
 *
 * Same transport as `turn-stream.php` (SSE over a per-channel Redis stream, with the stream id as the SSE `id:` so a
 * reconnect resumes where it stopped), with two differences that matter here:
 *
 *   - it carries no terminal event. A turn ends; the app's state simply keeps changing, so a viewer stays subscribed.
 *   - on connect it sends the *current* state for the requested conversation first (no stream id on that one), so a page
 *     that just loaded is correct immediately and never has to poll to find out where it stands.
 *
 * Two kinds of event arrive on the channel: `state` (one conversation's payload, computed by SessionState::payload) and
 * `engine` (the model lock, which is global — every conversation's page cares when another one takes the engine). Clients
 * filter by session id for the first kind and apply the second to all of them.
 *
 * See `.hermes/plans/evidence/2026-10-11_durable-model-queue-rulings.md` (R18) and App\Services\SessionState.
 */

require '/var/www/html/vendor/autoload.php';

App\Config::load('/var/www/html');

$sessionId = (int)($_GET['session'] ?? 0);
$from = (string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['from'] ?? '0'));

$stream = App\Services\SessionState::stream();
$deadline = time() + (int)(getenv('STATE_STREAM_MAX_SECONDS') ?: 600);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

ini_set('display_errors', '0');
@set_time_limit(0);
ignore_user_abort(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * One frame. `$id` empty means "no id" — used for the opening snapshot, which must not move the resume point.
 */
$emit = static function (string $id, string $event, array $data): void {
    if ($id !== '') {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
};

echo "retry: 3000\n\n";
@ob_flush();
@flush();

// Where this conversation stands right now, before any change arrives.
try {
    $emit('', 'state', App\Services\SessionState::payload($sessionId));
} catch (\Throwable $e) {
    // A state read that fails is not fatal: the page keeps its last known state and the next change will correct it.
}

$lastKeepAlive = 0.0;
while (time() < $deadline) {
    if (connection_aborted()) {
        exit;
    }

    try {
        $entries = $stream->read(App\Services\SessionState::CHANNEL, $from, 200);
    } catch (\Throwable $e) {
        // The relay says so rather than dying silently; the client keeps its state and reconnects.
        $emit('', 'error', ['message' => 'State stream unavailable: ' . $e->getMessage()]);
        exit;
    }

    foreach ($entries as $entry) {
        $from = (string)$entry['id'];
        $emit((string)$entry['id'], (string)$entry['event'], (array)$entry['data']);
    }

    // A comment frame keeps proxies and browsers from treating a quiet stream as dead. Longer than the read interval
    // because the read interval is short: this only has to beat the proxy's idle timeout.
    if (microtime(true) - $lastKeepAlive > 15.0) {
        echo ": keep-alive\n\n";
        @ob_flush();
        @flush();
        $lastKeepAlive = microtime(true);
    }

    // 100 ms: a state change is seen at most that late, against a 2 000 ms poll before it — and the read is one Redis
    // round trip on a stream that is empty almost always.
    usleep(100_000);
}
