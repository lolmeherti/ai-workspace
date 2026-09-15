<?php
// Development-only router. It is outside src/ and is not shipped in the image.
// Run: php -S 127.0.0.1:8081 -t src tests/ui/preview.php
if (PHP_SAPI === 'cli') {
    parse_str($argv[1] ?? 'session_id=3&tab=chats', $_GET);
    $_SERVER['REQUEST_URI'] = '/index.php';
} elseif (PHP_SAPI !== 'cli-server') {
    exit("Development preview only.\n");
}
$root = dirname(__DIR__, 2) . '/src';
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($requestPath !== '/' && $requestPath !== '/index.php') {
    return false;
}
spl_autoload_register(function ($class) use ($root) {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) require_once $path;
    }
});
$sessions = [
    ['id' => 3, 'title' => 'Designing a clearer workspace', 'context_tokens' => 4820, 'is_starred' => 1],
    ['id' => 2, 'title' => 'Research notes and source comparison', 'context_tokens' => 2100, 'is_starred' => 0],
    ['id' => 1, 'title' => 'A very long conversation title that should remain easy to select', 'context_tokens' => 830, 'is_starred' => 0],
];
$sessionId = (int)($_GET['session_id'] ?? 3);
$activeTab = $_GET['tab'] ?? 'chats';
$activeSessionTitle = $sessions[3 - $sessionId]['title'] ?? 'New conversation';
$totalSessionTokens = 4820;
$history = $sessionId === 0 ? [] : [
    ['id' => 100 + $sessionId, 'role' => 'user', 'message' => 'Can you help me organise the context for this project?'],
    ['id' => 200 + $sessionId, 'role' => 'assistant', 'model' => 'Local assistant', 'message' => "## A clear place for your work\n\nKeep the conversation in focus and open **Context Data** when you need the original evidence.\n\n- Read the source before making changes.\n- Review extracted facts together.\n- Keep the next action visible.\n\n| Item | Purpose |\n| --- | --- |\n| Original evidence | The material you collected |\n| Extracted facts | The details retained for later |\n\nA small example:\n\n```js\nconst workspace = { clear: true, responsive: true };\n```", 'rating' => null, 'rating_reason' => null, 'had_tool_calls' => 1],
    ['id' => 300 + $sessionId, 'role' => 'tool', 'message_type' => 'data_fetching', 'message' => 'Original source material for the project.', 'search_query' => 'Workspace interface patterns and interaction guidance', 'tool_name' => 'search_web', 'source_map' => '{"1":{"url":"https://example.com/research","title":"Research notes"}}', 'token_estimate' => 2100, 'atomic_tokens' => 380, 'atomic_context' => 'Keep actions visible. Preserve source provenance.', 'raw_evicted' => 0],
];
$memories = [
    ['id' => 1, 'created_at' => '2026-09-01 10:00:00', 'memory_text' => 'Use clear labels and preserve the current draft when changing workspaces.'],
    ['id' => 2, 'created_at' => '2026-09-02 10:00:00', 'memory_text' => 'Keep original research separate from extracted facts.'],
];
$memoryCount = count($memories);
$status = (object)['database' => true, 'redis' => true, 'ai' => true, 'all_operational' => true, 'model_name' => 'Local assistant'];
$envVars = ['LLM_CTX_SIZE' => '32768'];
$modelsList = [];
$db = new class {
    public function query($sql, $params = []) {
        if (str_contains($sql, 'email_accounts')) return [['id' => 1, 'label' => 'Personal', 'email_address' => 'demo@example.com', 'provider' => 'gmail']];
        if (str_contains($sql, 'COUNT')) return [['total' => 0]];
        return [];
    }
};
$api = $_GET['api_action'] ?? '';
if ($api === 'get_conversation') {
    ob_start(); include $root . '/views/chat-history.php'; $messages = ob_get_clean();
    ob_start(); include $root . '/views/context-items.php'; $context = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'session_id' => $sessionId, 'title' => $activeSessionTitle, 'tokens' => $totalSessionTokens, 'messages_html' => $messages, 'context_html' => $context]);
    exit;
}
if ($api !== '') {
    header('Content-Type: application/json');
    $data = match ($api) {
        'get_ai_availability' => ['status' => 'success', 'state' => 'ready', 'message' => 'AI ready'],
        'get_reasoning_effort' => ['status' => 'success', 'mode' => 'effort', 'value' => 'medium', 'effort' => 'medium'],
        'list_cvs' => ['status' => 'success', 'cvs' => [['uuid' => 'cv-1', 'designation' => 'Backend engineer', 'active_flag' => 1, 'extracted_markdown' => 'Experienced software engineer.']]],
        'get_profile' => ['status' => 'success', 'complete' => true, 'profile' => ['locations' => ['Vienna'], 'work_modes' => ['remote', 'hybrid'], 'employment_types' => ['full-time'], 'salary_currency' => 'EUR']],
        'list_registry' => ['status' => 'success', 'entries' => []],
        'list_jobs' => ['status' => 'success', 'jobs' => [], 'total' => 0, 'counts' => ['unread' => 0, 'interested' => 0, 'applied' => 0, 'interview' => 0, 'offer' => 0, 'history' => 0]],
        default => ['status' => 'success', 'files' => [], 'emails' => [], 'blocks' => [], 'total' => 0, 'logs' => [], 'run' => null],
    };
    echo json_encode($data);
    exit;
}
// Render the real page shell/templates without booting database/model services.
// No private configuration is loaded. API fixtures are deliberately synthetic.
$page = file_get_contents($root . '/index.php');
$page = substr($page, strpos($page, '<!DOCTYPE html>'));
$page = str_replace('__DIR__', var_export($root, true), $page);
$page = str_replace('Config::', '\\App\\Config::', $page);
eval('?>' . $page);
