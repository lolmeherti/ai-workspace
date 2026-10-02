<?php

declare(strict_types=1);

/*
 * Endpoint stand-in for testing the endpoint-error path without a real engine.
 *
 *   GET  …/models            → 200 with one model, so the app's health check passes
 *   POST …/chat/completions   → 400 with an OpenAI-style error body
 *   anything else             → 404
 *
 * Purpose: the app's health probe and its completion call share one base URL, so a
 * plain wrong URL fails the health check and the turn is refused before the
 * completion is ever attempted ("The AI service is offline. Check the launcher.").
 * This stub keeps the health check green while the completion fails, which is the
 * only way to exercise ChatStreamAction's endpoint_error path end to end.
 *
 * Run inside the web container:
 *   docker exec -d ai_php_web php -S 127.0.0.1:9091 /var/www/html/tests/live/endpoint-stub-server.php
 * then point LLM_API_URL at http://127.0.0.1:9091/v1 for one turn.
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

header('Content-Type: application/json');

if (str_ends_with($path, '/models')) {
    echo json_encode([
        'object' => 'list',
        'data' => [['id' => 'endpoint-stub', 'object' => 'model', 'owned_by' => 'localsy-test']],
    ]);
    return true;
}

if (str_ends_with($path, '/chat/completions')) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'message' => 'this server was started without the vision encoder (endpoint stub)',
            'type' => 'invalid_request_error',
        ],
    ]);
    return true;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'not found (endpoint stub)']]);
return true;
