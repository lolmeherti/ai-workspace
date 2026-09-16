<?php
// Development-only router. It renders the real shell with synthetic data.
// Run: php -S 127.0.0.1:8081 -t src tests/ui/preview.php
if (PHP_SAPI === 'cli') {
    parse_str($argv[1] ?? 'session_id=3&tab=chats', $_GET);
    $_SERVER['REQUEST_URI'] = '/index.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
} elseif (PHP_SAPI !== 'cli-server') exit("Development preview only.\n");
$root = dirname(__DIR__, 2) . '/src';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/index.php', PHP_URL_PATH);
if ($requestPath !== '/' && $requestPath !== '/index.php') return false;
require_once __DIR__ . '/fixture-data.php';
require_once __DIR__ . '/fixture-api.php';
spl_autoload_register(function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require_once $path;
});

$requestedScenario = $_GET['fixture'] ?? 'populated';
$scenario = in_array($requestedScenario, ['populated', 'empty', 'busy', 'error'], true) ? $requestedScenario : 'populated';
if (PHP_SAPI === 'cli-server') {
    $sessionPath = sys_get_temp_dir() . '/localsy-ui-fixture';
    if (!is_dir($sessionPath)) mkdir($sessionPath, 0700, true);
    session_name('localsy_ui_fixture'); session_save_path($sessionPath); session_start();
    if (isset($_GET['reset_fixture']) || !isset($_SESSION['fixture_data']) || ($_SESSION['fixture_scenario'] ?? null) !== $scenario) {
        $_SESSION['fixture_data'] = uiFixtureData($scenario);
        $_SESSION['fixture_scenario'] = $scenario;
    }
    $fixtureData = &$_SESSION['fixture_data'];
} else $fixtureData = uiFixtureData($scenario);

$api = $_GET['api_action'] ?? '';
$postAction = $_POST['action'] ?? '';
foreach (['manual_consolidate', 'add_memory', 'update_memory', 'delete_memory', 'delete_multiple_memories'] as $action) if (isset($_POST[$action])) $postAction = $action;
if ($api !== '' || $postAction !== '') {
    $data = uiFixtureApi($fixtureData, $api ?: $postAction, array_merge($_GET, $_POST));
    http_response_code($data['_http'] ?? 200); unset($data['_http']);
    if (isset($data['_events'])) {
        header('Content-Type: text/event-stream'); header('Cache-Control: no-cache');
        foreach ($data['_events'] as $event) { echo 'data: ' . json_encode($event) . "\n\n"; if (ob_get_level()) ob_flush(); flush(); if (PHP_SAPI === 'cli-server') usleep(650000); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); exit;
    }
    if ($postAction === 'manual_consolidate' && PHP_SAPI === 'cli-server') usleep(1000000);
    if ($api === '' && $postAction !== 'condense' && !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Location: /index.php?session_id=' . (int)($_POST['session_id'] ?? 3) . '&tab=memories'); exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit;
}

$sessions = [
    ['id' => 3, 'title' => 'Designing a clearer workspace', 'context_tokens' => 4820, 'is_starred' => 1],
    ['id' => 2, 'title' => 'Research notes and source comparison', 'context_tokens' => 2100, 'is_starred' => 0],
    ['id' => 1, 'title' => 'A very long conversation title that should remain easy to select', 'context_tokens' => 830, 'is_starred' => 0],
];
$sessionId = (int)($_GET['session_id'] ?? 3); $activeTab = $_GET['tab'] ?? 'chats';
$activeSessionTitle = $sessionId === 3 ? $sessions[0]['title'] : ($sessionId === 2 ? $sessions[1]['title'] : 'New conversation');
$totalSessionTokens = $fixtureData['condensed'] ? 1200 : 4820;
$history = $sessionId === 0 ? [] : [
    ['id' => 100 + $sessionId, 'role' => 'user', 'message' => 'Can you help me organise the context for this project?'],
    ['id' => 200 + $sessionId, 'role' => 'assistant', 'model' => 'Local assistant', 'message' => "## A clear place for your work\n\nKeep the conversation in focus and open **Context Data** when you need the original evidence.\n\n- Read the source before making changes.\n- Review extracted facts together.\n- Keep the next action visible.", 'rating' => null, 'rating_reason' => null, 'had_tool_calls' => 1],
    ['id' => 300 + $sessionId, 'role' => 'tool', 'message_type' => 'data_fetching', 'message' => 'Original source material for the project.', 'search_query' => 'Workspace interface patterns', 'tool_name' => 'search_web', 'source_map' => '{"1":{"url":"https://example.com/research","title":"Research notes"}}', 'token_estimate' => 2100, 'atomic_tokens' => 380, 'atomic_context' => 'Keep actions visible. Preserve source provenance.', 'raw_evicted' => 0],
];
$memories = $fixtureData['memories']; $memoryCount = count($memories);
$status = (object)['database' => true, 'redis' => true, 'ai' => $scenario !== 'error', 'all_operational' => $scenario !== 'error', 'model_name' => 'Local fixture assistant'];
$envVars = ['LLM_CTX_SIZE' => '32768']; $modelsList = [];
$db = new class { public function query($sql, $params = []) { if (str_contains($sql, 'email_accounts')) return [['id' => 1, 'label' => 'Fixture mailbox', 'email_address' => 'fixture@example.test', 'provider' => 'mock']]; if (str_contains($sql, 'COUNT')) return [['total' => 0]]; return []; } };
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Render the production page shell/templates without booting database/model services.
$page = file_get_contents($root . '/index.php'); $page = substr($page, strpos($page, '<!DOCTYPE html>'));
$page = str_replace('__DIR__', var_export($root, true), $page); $page = str_replace('Config::', '\\App\\Config::', $page);
eval('?>' . $page);
