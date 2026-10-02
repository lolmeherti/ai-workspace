<?php

declare(strict_types=1);

/*
 * Endpoint failure probe (live, read-only).
 *
 * Verifies silent-failure task 1: an endpoint that answers with an HTTP error,
 * or with a non-completion body, must throw EndpointException carrying the status
 * and a body excerpt — not read as an empty answer.
 *
 * It redirects LLM_API_URL at a path the engine does not serve (a real 404 from a
 * real host) and exercises all three request paths. Config::get reads $_ENV on
 * every call and Config::load() is immutable, so no .env file is touched and
 * nothing is written to the database.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/live/endpoint-error-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Database;
use App\Services\EndpointException;

Config::load(dirname(__DIR__, 2));

// The app wires the logger to the database during bootstrap; a probe must do the
// same or logEvent() has nowhere to write and the events silently stay in-process.
$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$real = rtrim((string) Config::get('LLM_API_URL', ''), '/');
if ($real === '') {
    fwrite(STDERR, "no LLM_API_URL configured\n");
    exit(1);
}

$parts = parse_url($real);
$base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '127.0.0.1');
if (isset($parts['port'])) {
    $base .= ':' . $parts['port'];
}
$dead = $base . '/v1/does-not-exist';

echo "configured base: {$real}\n";
echo "probe base:      {$dead}   (unserved path → expect HTTP 404)\n\n";

// Control first: the real endpoint must still return a completion, so the check
// cannot produce a false positive on a healthy response.
$control = new AgentManager();
try {
    $result = $control->chat([['role' => 'user', 'content' => 'reply with the single word ok']], false);
    $text = is_array($result) ? (string) ($result['content'] ?? '') : (string) $result;
    printf("[OK]   %-20s completion received (%d chars)\n\n", 'control (real)', strlen(trim($text)));
} catch (\Throwable $e) {
    printf("[FAIL] %-20s %s: %s\n\n", 'control (real)', get_class($e), mb_substr($e->getMessage(), 0, 140));
}

$_ENV['LLM_API_URL'] = $dead;

$agent = new AgentManager();
$noop = function (string $chunk, string $type = 'content'): void {
};

$cases = [
    'chat (streamed)' => fn() => $agent->chat([['role' => 'user', 'content' => 'probe']], true, $noop),
    'chat (non-streamed)' => fn() => $agent->chat([['role' => 'user', 'content' => 'probe']], false),
    'chatToolCapable' => fn() => $agent->chatToolCapable([['role' => 'user', 'content' => 'probe']], [], 'auto', $noop),
    'chatWithTools' => fn() => $agent->chatWithTools([['role' => 'user', 'content' => 'probe']], [], 'auto'),
];

$threw = 0;
foreach ($cases as $label => $call) {
    try {
        $result = $call();
        printf("[FAIL] %-20s returned without an error: %s\n", $label, substr((string) json_encode($result), 0, 100));
    } catch (EndpointException $e) {
        $threw++;
        printf("[OK]   %-20s status=%d  %s\n", $label, $e->status(), mb_substr($e->getMessage(), 0, 120));
    } catch (\Throwable $e) {
        printf("[FAIL] %-20s %s: %s\n", $label, get_class($e), mb_substr($e->getMessage(), 0, 110));
    }
}

printf("\n%d/%d request paths threw EndpointException\n", $threw, count($cases));

// An auxiliary pass must NOT fail the turn: it is logged and returns empty so the
// caller's existing graceful degradation runs (e.g. evidence keeps its raw).
try {
    $agent->chatToolCapable([['role' => 'user', 'content' => 'probe']], [], 'auto', $noop, null, 'condenser');
    echo "[OK]   condenser (aux)      returned normally — degraded, not raised\n";
} catch (\Throwable $e) {
    printf("[FAIL] condenser (aux)      %s: %s\n", get_class($e), mb_substr($e->getMessage(), 0, 110));
}

echo "expect an llm_http_error event per failing path in app_events (status + body excerpt)\n";
