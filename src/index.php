<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

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
use App\Bootstrap\PageDataLoader;

Config::load(__DIR__);

try {
    $envEditor = new EnvEditor(__DIR__ . '/.env');
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'chats';

    $envVars = $envEditor->read();

    $status = null;
    try {
        $cached = Cache::get('system_health_status');
        if ($cached) {
            $status = json_decode($cached);
        }
    } catch (\Exception $e) {}

    if ($status === null) {
        $status = (new HealthCheck())->check();
        try {
            Cache::set('system_health_status', json_encode($status), 10);
        } catch (\Exception $e) {}
    }

    $db = $status->database ? new Database() : null;
    if ($db !== null) {
        \App\Logger::setDatabase($db);
    }
    $agentManager = new AgentManager();
    $memoryExtractor = $db ? new MemoryExtractor($db, $agentManager) : null;

    require_once __DIR__ . '/actions.php';

    // Fetch available models from Go API for the settings dropdown
    $modelsList = [];
    try {
        $goHost = Config::get('LLM_API_URL', 'http://host.docker.internal:1234/v1');
        $goHost = str_replace('/v1', '', rtrim($goHost, '/'));
        // The Go API runs on the same host as llama but uses port 9876 instead of 1234
        $modelsUrl = preg_replace('#:\d{1,5}/?$#', ':9876/api/models', $goHost);
        if ($modelsUrl === '' || $modelsUrl === $goHost) {
            $modelsUrl = 'http://host.docker.internal:9876/api/models';
        }

        $ch = curl_init($modelsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        if ($response = @curl_exec($ch)) {
            $modelsList = json_decode($response, true) ?: [];
        }
        curl_close($ch);
    } catch (\Exception $_e) {}

    $pageData = (new PageDataLoader())->load($db, $chatSessionRepository, $memoryRepository, $sessionId, $status);
    extract($pageData);
} catch (\App\Services\ModelBusyException $e) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
} catch (\Throwable $e) {
    \App\Logger::critical("Bootstrap failure in index.php", [
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'get' => $_GET
    ], $e);
    
    if (ini_get('display_errors')) {
        echo "<h1>Fatal Bootstrap Error</h1>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Continuous Chat Session</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        slate: {
                            750: '#2a3b55',
                            850: '#182236',
                        },
                    },
                },
            },
        };
    </script>
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
        
        <div id="chat-workspace" class="flex-1 flex flex-col h-full min-w-0">
            <?php include __DIR__ . '/views/chat-window.php'; ?>
        </div>

        <div id="gallery-workspace" class="flex-1 flex flex-col h-full min-w-0 hidden">
            <?php include __DIR__ . '/views/gallery-workspace.php'; ?>
        </div>

        <div id="email-workspace" class="flex-1 flex flex-col h-full min-w-0 hidden bg-[#040810]">
            <?php include __DIR__ . '/views/email-workspace.php'; ?>
        </div>

        <div id="job-workspace" class="flex-1 flex flex-col h-full min-w-0 hidden bg-[#040810]">
            <?php include __DIR__ . '/views/job-workspace.php'; ?>
        </div>
    </div>

    <?php include __DIR__ . '/views/modal-settings.php'; ?>

    <script>
        const currentActiveTab = '<?php echo $activeTab; ?>';
        const initialSessionTokens = <?php echo $totalSessionTokens; ?>;
        const maxTokensLimit = <?php echo (int) Config::get('LLM_CTX_SIZE', 32768); ?>;
    </script>
    <script type="module" src="js/app.js"></script>
    <script type="module" src="js/gallery/galleryBootstrap.js"></script>
    <script type="module" src="js/email/emailWorkspaceBootstrap.js"></script>
    <script type="module" src="js/jobs/jobWorkspaceBootstrap.js"></script>
</body>
</html>