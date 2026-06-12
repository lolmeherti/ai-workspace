<?php

use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;
use App\Routing\ActionRouter;

$memoryRepository = new MemoryRepository($db);
$chatSessionRepository = new ChatSessionRepository($db);

$router = new ActionRouter(
    $db,
    $memoryRepository,
    $chatSessionRepository,
    $memoryExtractor,
    $agentManager,
    $status,
    $envEditor
);
$router->route();
