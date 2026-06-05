<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\HealthCheck;
use App\AgentManager;
use App\Agents\MemoryExtractor;
use App\EnvEditor;
use App\Cache;
use App\Repositories\MemoryRepository;
use App\Repositories\ChatSessionRepository;

Config::load(__DIR__);

$envEditor = new EnvEditor(__DIR__ . '/.env');
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'chats';

$envVars = $envEditor->read();
$status = (new HealthCheck())->check();

$db = $status->database ? new Database() : null;
$agentManager = new AgentManager();
$memoryExtractor = $db ? new MemoryExtractor($db, $agentManager) : null;

require_once __DIR__ . '/actions.php';

$sessions = [];
$activeSessionTitle = 'New Conversation';
$history = [];
$totalSessionTokens = 0;
$memories = [];
$memoryCount = 0;
$queries = [];

if ($db) {
    $sessions = $chatSessionRepository->getAllDesc();
    foreach ($sessions as $s) {
        if ((int)$s['id'] === $sessionId) {
            $activeSessionTitle = $s['title'];
            break;
        }
    }

    $history = $chatSessionRepository->getHistory($sessionId);
    foreach ($history as $msg) {
        $totalSessionTokens += (int)($msg['token_estimate'] ?? 0);
    }

    try {
        $memories = $memoryRepository->getAllLimit500();
        $memoryCount = count($memories);
    } catch (\Exception $e) {}
}

if ($status->redis) {
    try {
        $queries = Cache::getSearchLedger();
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Continuous Chat Session</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/franken-ui@2.1.2/dist/css/core.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/franken-ui@2.1.2/dist/js/core.iife.js" type="module"></script>
    <script src="https://cdn.jsdelivr.net/npm/franken-ui@2.1.2/dist/js/icon.iife.js" type="module"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked-katex-extension@5.1.2/lib/index.umd.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="h-screen w-screen flex overflow-hidden antialiased selection:bg-cyan-500/30">

    <div class="h-full w-full flex">
        <?php include __DIR__ . '/views/sidebar.php'; ?>
        <?php include __DIR__ . '/views/chat-window.php'; ?>
    </div>

    <?php include __DIR__ . '/views/modal-settings.php'; ?>

    <script>
        const currentActiveTab = '<?php echo $activeTab; ?>';
        const initialSessionTokens = <?php echo $totalSessionTokens; ?>;
        const maxTokensLimit = <?php echo (int) Config::get('MEMORY_EXTRACTION_THRESHOLD_TOKENS', 15000); ?>;
    </script>
    <script src="js/chat.js"></script>
</body>
</html>