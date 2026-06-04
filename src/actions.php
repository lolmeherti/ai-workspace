<?php
use App\ChatManager;
use App\Cache;
use App\Agents\SearchDecider;
use App\Agents\SemanticCacheEvaluator;
use App\Agents\ContextCondenser;
use App\Agents\MemorySelector;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false || strpos($_SERVER['HTTP_ACCEPT'], 'text/event-stream') !== false);
    
    if ($isApiRequest) {
        if (!$status->all_operational || !$db) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'System offline.']);
            exit;
        }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $query = $_POST['q'] ?? '';
        $imageFile = $_FILES['image'] ?? null;
        $cacheAction = $_POST['cache_action'] ?? null;
        $cacheKey = $_POST['cache_key'] ?? null;

        if (empty($query) && empty($imageFile) && empty($cacheAction)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Empty prompt.']);
            exit;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @set_time_limit(600);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        
        echo ":" . str_repeat(" ", 4096) . "\n\n";
        @flush();

        $searchDecider = clone new SearchDecider($agentManager);
        $cacheEvaluator = clone new SemanticCacheEvaluator($agentManager);
        $contextCondenser = clone new ContextCondenser($agentManager);
        $memorySelectorInstance = clone $db ? new MemorySelector($db, $agentManager) : null;

        $chatManager = clone new ChatManager(
            $db, 
            $agentManager, 
            $memoryExtractor, 
            $memorySelectorInstance,
            $searchDecider,
            $cacheEvaluator,
            $contextCondenser
        );

        $chatManager->process($sessionId, $query, $imageFile, $cacheAction, $cacheKey, function($event, $data) {
            echo "data: " . json_encode(['event' => $event, 'data' => $data]) . "\n\n";
            @ob_flush();
            @flush();
        });
        
        exit;
    }

    if (isset($_POST['save_settings'])) {
        $currentEnv = $envEditor->read();
        $newEnv = [];
        
        foreach (array_keys($currentEnv) as $key) {
            if (isset($_POST[$key])) {
                $newEnv[$key] = $_POST[$key];
            }
        }
        
        $envEditor->write($newEnv);
        header("Location: index.php?session_id=" . $sessionId . "&tab=" . $activeTab);
        exit;
    }

    if ($db && isset($_POST['add_memory'])) {
        $count = $db->getConnection()->query("SELECT COUNT(*) FROM memories")->fetchColumn();
        if ($count < 500) {
            $text = trim($_POST['memory_text'] ?? '');
            if (!empty($text)) {
                $db->insert('memories', ['memory_text' => $text]);
            }
        }
        header("Location: index.php?session_id=" . $sessionId . "&tab=memories");
        exit;
    }

    if ($db && isset($_POST['delete_memory'])) {
        $memoryId = $_POST['memory_id'] ?? '';
        $stmt = $db->getConnection()->prepare("DELETE FROM memories WHERE id = :id");
        $stmt->execute([':id' => $memoryId]);
        header("Location: index.php?session_id=" . $sessionId . "&tab=memories");
        exit;
    }

    if ($db && isset($_POST['update_memory'])) {
        $memoryId = $_POST['memory_id'] ?? '';
        $text = trim($_POST['memory_text'] ?? '');
        if (!empty($text)) {
            $db->update('memories', ['memory_text' => $text], ['id' => $memoryId]);
        }
        header("Location: index.php?session_id=" . $sessionId . "&tab=memories");
        exit;
    }

    if ($status->redis && isset($_POST['delete_query'])) {
        $cacheKey = $_POST['cache_key'] ?? '';
        if (!empty($cacheKey)) {
            $ledger = Cache::getSearchLedger();
            $filteredLedger = array_filter($ledger, fn($item) => $item['cache_key'] !== $cacheKey);
            Cache::set('search_ledger', json_encode(array_values($filteredLedger)), 604800);
            Cache::delete($cacheKey);
        }
        header("Location: index.php?session_id=" . $sessionId . "&tab=queries");
        exit;
    }

    $clearAll = $_POST['clear_all'] ?? '';
    if ($db && $clearAll === '1') {
        $db->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $db->getConnection()->exec("TRUNCATE TABLE chat_history;");
        $db->getConnection()->exec("TRUNCATE TABLE chat_sessions;");
        $db->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 1;");
        header("Location: index.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $newChat = $_GET['new_chat'] ?? '';
    $deleteSessionId = $_GET['delete_session'] ?? '';

    if ($db && !empty($deleteSessionId)) {
        $stmt = $db->getConnection()->prepare("DELETE FROM chat_sessions WHERE id = :id");
        $stmt->execute([':id' => $deleteSessionId]);
        header("Location: index.php");
        exit;
    }

    if ($db && $newChat === '1') {
        $db->insert('chat_sessions', ['title' => 'New Conversation']);
        $newId = $db->getConnection()->lastInsertId();
        header("Location: index.php?session_id=" . $newId . "&tab=" . $activeTab);
        exit;
    }
}