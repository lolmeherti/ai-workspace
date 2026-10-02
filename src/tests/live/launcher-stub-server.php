<?php

declare(strict_types=1);

/*
 * Launcher-API stand-in for testing the offline message.
 *
 * /api/switch-status answers with a boot-time engine failure — the shape the real
 * launcher publishes when the engine could not be started or never became ready
 * (internal/launcher/engineerror.go), so HealthCheck's precedence can be exercised
 * without breaking a real boot.
 *
 * Run: docker exec -d ai_php_web php -S 127.0.0.1:9876 /var/www/html/tests/live/launcher-stub-server.php
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

header('Content-Type: application/json');

if (str_ends_with($path, '/api/switch-status')) {
    echo json_encode([
        'active' => false,
        'stage' => 'loaded',
        'engine_error' => [
            'message' => 'The stub engine failed to start (launcher stub)',
            'detail' => 'the launcher could not spawn the engine process — see the launcher log',
            'at' => gmdate('c'),
        ],
    ]);
    return true;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'not found (launcher stub)']]);
return true;
