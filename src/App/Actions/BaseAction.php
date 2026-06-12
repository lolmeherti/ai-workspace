<?php

namespace App\Actions;

use App\Enums\Tab;

abstract class BaseAction
{
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function redirect(string $url): void
    {
        header("Location: " . $url);
        exit;
    }

    protected function buildUrl(int $sessionId, Tab $tab): string
    {
        return "index.php?session_id=" . $sessionId . "&tab=" . $tab->value;
    }

    protected function isApiRequest(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($acceptHeader, 'application/json') !== false || strpos($acceptHeader, 'text/event-stream') !== false;
    }
}
