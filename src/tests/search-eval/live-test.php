<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Search\SearchPipeline;

Config::load(__DIR__ . '/../..');

$query = "What is the battery life of the iPhone 16 Pro";
$emit = function(string $stage, array $data = []) {
    $ts = date('H:i:s');
    echo "[$ts] $stage";
    if (!empty($data)) echo ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
    echo "\n";
};

echo "=== LIVE PIPELINE TEST ===\n";
echo "Query: $query\n\n";

$start = microtime(true);

try {
    $agent = new AgentManager();
    $pipeline = new SearchPipeline($agent);
    $result = $pipeline->run($query, [], $emit);

    $elapsed = round((microtime(true) - $start) * 1000);
    $evidenceLen = strlen($result['evidence'] ?? '');
    $sourceCount = count($result['sourceIds'] ?? []);
    $preview = substr($result['evidence'] ?? '', 0, 500);

    echo "\n=== RESULT ===\n";
    echo "Status:    SUCCESS\n";
    echo "Elapsed:   {$elapsed}ms\n";
    echo "Evidence:  {$evidenceLen} chars\n";
    echo "Sources:   {$sourceCount} (" . implode(', ', $result['sourceIds'] ?? []) . ")\n";
    echo "\n--- Evidence preview (first 500 chars) ---\n";
    echo $preview . "\n";
    echo "--- end preview ---\n";

} catch (\Throwable $e) {
    $elapsed = round((microtime(true) - $start) * 1000);
    echo "\n=== RESULT ===\n";
    echo "Status:    FAILED\n";
    echo "Elapsed:   {$elapsed}ms\n";
    echo "Exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File:      " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
