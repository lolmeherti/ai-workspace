<?php

declare(strict_types=1);

/*
 * Endpoint-error turn probe (live, self-cleaning).
 *
 * Proves the failure reaches the user instead of becoming an empty answer, at the
 * *turn* level rather than just the agent call: with the health probe green and the
 * completion rejected, ChatManager::process() must raise EndpointException carrying
 * the engine's own reason, and must not persist an empty assistant row.
 *
 * Requires the stub (its /models answers 200, so the app's health check is green —
 * a plain wrong URL would be refused before the completion is ever attempted):
 *   docker exec -d ai_php_web php -S 127.0.0.1:9091 /var/www/html/tests/live/endpoint-stub-server.php
 *
 * The scratch session it creates is deleted at the end.
 * Run: docker exec ai_php_web php /var/www/html/tests/live/endpoint-error-turn-probe.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\Services\EndpointException;

Config::load(dirname(__DIR__, 2));

$db = new Database();
$db->initTables();
\App\Logger::setDatabase($db);

$stub = 'http://127.0.0.1:9091/v1';
$_ENV['LLM_API_URL'] = $stub;
echo "endpoint-error turn probe (LLM_API_URL -> {$stub})\n\n";

$db->insert('chat_sessions', ['title' => 'endpoint-error-turn-probe', 'context_tokens' => 0]);
$sessionId = (int) $db->getConnection()->lastInsertId();

$manager = new ChatManager($db, new AgentManager());
$events = [];

try {
    $result = $manager->process($sessionId, 'probe: the completion is rejected', null, null, null, function (string $event, array $data = []) use (&$events): void {
        $events[] = $event;
    });
    printf("[FAIL] the turn returned instead of raising: %s\n", substr((string) json_encode($result), 0, 140));
} catch (EndpointException $e) {
    printf("[OK]   turn raised EndpointException (status %d)\n       %s\n", $e->status(), $e->getMessage());
} catch (\Throwable $e) {
    printf("[FAIL] unexpected %s: %s\n", get_class($e), substr($e->getMessage(), 0, 140));
}

$rows = $db->selectSafe('chat_history', ['session_id' => $sessionId], 'id', false);
$emptyAssistant = 0;
$userRows = 0;
foreach ($rows as $row) {
    $role = (string) ($row['role'] ?? '');
    if ($role === 'user') {
        $userRows++;
    }
    if ($role === 'assistant' && trim((string) ($row['message'] ?? '')) === '') {
        $emptyAssistant++;
    }
}
printf("[%s] no empty assistant row persisted (%d found; user rows: %d)\n", $emptyAssistant === 0 ? 'OK' : 'FAIL', $emptyAssistant, $userRows);
echo 'events emitted before the failure: ' . (empty($events) ? '(none)' : implode(', ', array_slice($events, 0, 10))) . "\n";

$db->query('DELETE FROM chat_sessions WHERE id = ?', [$sessionId]);
echo "scratch session {$sessionId} removed\n";
