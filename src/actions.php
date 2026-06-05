<?php

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Controllers\ChatController;

$memoryRepository = new MemoryRepository($db);
$chatSessionRepository = new ChatSessionRepository($db);

$controller = new ChatController(
    $db,
    $memoryRepository,
    $chatSessionRepository,
    $agentManager,
    $memoryExtractor,
    $envEditor,
    $status
);

$controller->handle();