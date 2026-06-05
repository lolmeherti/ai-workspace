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

Config::load(__DIR__);

$envEditor = new EnvEditor(__DIR__ . '/.env');
$sessionId = $_GET['session_id'] ?? '';
$activeTab = $_GET['tab'] ?? 'chats';

$envVars = $envEditor->read();
$status = (new HealthCheck())->check();

$db = $status->database ? new Database() : null;
$agentManager = new AgentManager();
$memoryExtractor = $db ? new MemoryExtractor($db, $agentManager) : null;

require_once __DIR__ . '/actions.php';

$sessions = $db ? $db->query("SELECT * FROM chat_sessions ORDER BY id DESC") : [];
$activeSessionTitle = 'New Conversation';

foreach ($sessions as $s) {
    if ((int)$s['id'] === (int)$sessionId) {
        $activeSessionTitle = $s['title'];
        break;
    }
}

$history = $db ? $db->selectSafe('chat_history', ['session_id' => $sessionId]) : [];

$totalSessionTokens = 0;
foreach ($history as $msg) {
    $totalSessionTokens += (int)($msg['token_estimate'] ?? 0);
}

$memories = [];
$memoryCount = 0;
if ($db) {
    try {
        $memories = $db->query("SELECT * FROM memories ORDER BY id DESC LIMIT 500");
        $memoryCount = count($memories);
    } catch (\Exception $e) {}
}

$queries = [];
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