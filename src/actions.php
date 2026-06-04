<?php
use App\ChatManager;
use App\Cache;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    
    if ($isApiRequest) {
        header('Content-Type: application/json');
        if (!$status->all_operational || !$db) {
            echo json_encode(['status' => 'error', 'message' => 'System offline.']);
            exit;
        }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $query = $_POST['q'] ?? '';
        $imageFile = $_FILES['image'] ?? null;

        if (empty($query) && empty($imageFile)) {
            echo json_encode(['status' => 'error', 'message' => 'Empty prompt.']);
            exit;
        }

        $chatManager = new ChatManager($db, $agentManager, $memoryExtractor);
        $response = $chatManager->process($sessionId, $query, $imageFile);
        
        echo json_encode($response);
        exit;
    }

    if (isset($_POST['save_settings'])) {
        $envEditor->write([
            'MAX_SCRAPE_TOKENS' => $_POST['max_scrape_tokens'] ?? '2500',
            'MEMORY_EXTRACTION_THRESHOLD_TOKENS' => $_POST['memory_threshold'] ?? '15000',
            'LLM_API_URL' => $_POST['llm_api_url'] ?? 'http://host.docker.internal:1234/v1',
            'LLM_MODEL_NAME' => $_POST['llm_model_name'] ?? 'local-model',
            'MAX_SEARCH_RESULTS_TO_SCRAPE' => $_POST['max_search_results'] ?? '3'
        ]);
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