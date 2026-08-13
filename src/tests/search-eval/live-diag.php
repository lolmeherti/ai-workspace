<?php
/**
 * Diagnostic: trace doLiveSearch end-to-end to find why evidence is 0-length.
 * No fixes — observation only.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Agents\ContextCondenser;
use App\Services\Tools\SearchWebTool;
use App\Search\SearchPipeline;

Config::load(__DIR__ . '/../..');

$query = "What is the battery life of the iPhone 16 Pro";
$messages = [];
$emit = function(string $stage, array $data = []) {
    $ts = date('H:i:s');
    echo "[$ts] $stage";
    if (!empty($data)) echo ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
    echo "\n";
};

echo "=== DIAGNOSTIC: doLiveSearch 0-length evidence ===\n\n";

// Step 1: Call the pipeline directly (no SearchWebTool) — baseline
echo "--- STEP 1: Direct SearchPipeline::run() ---\n";
$agent1 = new AgentManager();
$pipeline = new SearchPipeline($agent1);
try {
    $result1 = $pipeline->run($query, $messages, $emit);
    echo "Evidence length: " . strlen($result1['evidence'] ?? '') . "\n";
    echo "Source IDs:      " . implode(', ', $result1['sourceIds'] ?? ['NONE']) . "\n";
    echo "sourceMap:       " . count($result1['sourceMap'] ?? []) . "\n";
    echo "Pipeline:   OK\n\n";
} catch (\Throwable $e) {
    echo "Pipeline THREW: " . get_class($e) . ": " . $e->getMessage() . "\n\n";
}

// Step 2: Call liveSearch() static — what execute() calls internally
echo "--- STEP 2: SearchWebTool::liveSearch() static ---\n";
$agent2 = new AgentManager();
$condenser2 = new ContextCondenser($agent2);
try {
    $result2 = SearchWebTool::liveSearch($query, $messages, $emit, $condenser2, 0);
    echo "Evidence length: " . strlen($result2['evidence'] ?? '') . "\n";
    echo "Source IDs:      " . implode(', ', $result2['sourceIds'] ?? ['NONE']) . "\n";
    echo "sourceMap:       " . count($result2['sourceMap'] ?? []) . "\n";
    echo "liveSearch: OK\n\n";
} catch (\Throwable $e) {
    echo "liveSearch THREW: " . get_class($e) . ": " . $e->getMessage() . "\n\n";
}

// Step 3: Call doLiveSearch via reflection — isolate the instance call
echo "--- STEP 3: doLiveSearch via reflection (fresh agent) ---\n";
$refClass = new ReflectionClass(SearchWebTool::class);
$tool = $refClass->newInstanceWithoutConstructor();
$prop = $refClass->getProperty('contextCondenser');
$prop->setAccessible(true);
$agent3 = new AgentManager();
$prop->setValue($tool, new ContextCondenser($agent3));

$method = $refClass->getMethod('doLiveSearch');
$method->setAccessible(true);
try {
    $result3 = $method->invokeArgs($tool, [$query, $messages, $emit, 0]);
    echo "Return type:     " . gettype($result3) . "\n";
    echo "Return length:   " . (is_string($result3) ? strlen($result3) : 'N/A') . "\n";
    if (is_string($result3) && strlen($result3) === 0) {
        echo "RESULT: EMPTY STRING\n";
    }
} catch (\Throwable $e) {
    echo "doLiveSearch THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

// Step 4: Inspect doLiveSearch source code closely
echo "\n--- STEP 4: Source verification ---\n";
$src = file_get_contents(__DIR__ . '/../../App/Services/Tools/SearchWebTool.php');
$lines = explode("\n", $src);
echo "doLiveSearch (lines 115-119):\n";
for ($i = 114; $i <= min(118, count($lines)-1); $i++) {
    echo "  L" . ($i+1) . ": " . $lines[$i] . "\n";
}

// Step 5: Check if liveSearchLegacy is being hit
echo "\n--- STEP 5: Check app_events for search_pipeline_failed ---\n";
try {
    $db = new \App\Database();
    $stmt = $db->executeStatement(
        "SELECT event_type, message, created_at FROM app_events WHERE event_type = 'search_pipeline_failed' ORDER BY created_at DESC LIMIT 5"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No search_pipeline_failed events found.\n";
    } else {
        foreach ($rows as $row) {
            echo "  [{$row['created_at']}] {$row['message']}\n";
        }
    }
} catch (\Throwable $e) {
    echo "DB query failed: " . $e->getMessage() . "\n";
}
