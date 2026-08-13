<?php
/**
 * Tests the tool-turn path to verify sourceIds flow (or don't).
 * Exercises: liveSearch() static → doLiveSearch() instance.
 *
 * Usage: docker exec ai_php_web php /var/www/html/tests/search-eval/live-toolturn.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Agents\ContextCondenser;
use App\Services\Tools\SearchWebTool;

Config::load(__DIR__ . '/../..');

$query = "What is the battery life of the iPhone 16 Pro";
$messages = [];
$emit = function(string $stage, array $data = []) {
    $ts = date('H:i:s');
    echo "[$ts] $stage";
    if (!empty($data)) echo ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
    echo "\n";
};

echo "=== TOOL-TURN PATH TEST ===\n";
echo "Query: $query\n\n";

// Path 1: static liveSearch() — used by ChatManager force_live
echo "--- Path 1: static liveSearch() (force_live path) ---\n";
$agent = new AgentManager();
$condenser = new ContextCondenser($agent);
$result = SearchWebTool::liveSearch($query, $messages, $emit, $condenser, 0);

echo "evidence length: " . strlen($result['evidence'] ?? '') . "\n";
echo "sourceIds:       " . implode(', ', $result['sourceIds'] ?? ['NONE']) . "\n";
echo "sourceMap:       " . count($result['sourceMap'] ?? []) . " sources\n";
$path1ok = !empty($result['sourceIds']);
echo "Status: " . ($path1ok ? "OK — sourceIds present" : "BROKEN — no sourceIds") . "\n\n";

// Path 2: instance doLiveSearch() — called by execute() tool-turn path
// Use reflection to call without full constructor deps
echo "--- Path 2: instance doLiveSearch() (tool-turn path) ---\n";
$refClass = new ReflectionClass(SearchWebTool::class);
$tool = $refClass->newInstanceWithoutConstructor();

// Set only the property doLiveSearch needs
$prop = $refClass->getProperty('contextCondenser');
$prop->setAccessible(true);
$prop->setValue($tool, new ContextCondenser($agent));

$method = $refClass->getMethod('doLiveSearch');
$method->setAccessible(true);
$doLiveResult = $method->invokeArgs($tool, [$query, $messages, $emit, 0]);

echo "return type:     " . gettype($doLiveResult) . "\n";
echo "return length:   " . (is_string($doLiveResult) ? strlen($doLiveResult) : 'N/A') . "\n";
echo "is string only:  " . (is_string($doLiveResult) ? 'YES — sourceIds STRIPPED' : 'NO — structured') . "\n";
$path2ok = !is_string($doLiveResult);
echo "Status: " . ($path2ok ? "OK — returns structured" : "BUG — doLiveSearch strips sourceIds to bare string") . "\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Path 1 (force_live, static liveSearch):  " . ($path1ok ? "PASS" : "FAIL") . "\n";
echo "Path 2 (tool-turn, instance doLiveSearch): " . ($path2ok ? "PASS" : "FAIL — needs fix: return array, extract in execute()") . "\n";
