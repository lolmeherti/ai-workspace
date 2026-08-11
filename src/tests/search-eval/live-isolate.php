<?php
/**
 * Isolate each pipeline stage to find the silent failure point.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\AgentManager;
use App\Config;
use App\Enums\SearchIntent;
use App\Search;
use App\Search\SearchPipeline;
use App\Search\OutboundScheduler;

Config::load(__DIR__ . '/../..');

$query = "iPhone 16 Pro battery life";

echo "=== ISOLATION TEST ===\n\n";

// 1. OutboundScheduler
echo "--- 1. OutboundScheduler::waitForSlot() ---\n";
try {
    $s = new OutboundScheduler();
    $s->waitForSlot('searxng', true);
    echo "OK — slot acquired and waited\n\n";
} catch (\Throwable $e) {
    echo "FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n\n";
}

// 2. SearchIntent::classify
echo "--- 2. SearchIntent::classify() ---\n";
try {
    $intent = SearchIntent::classify($query);
    echo "Intent: " . $intent->name . "\n\n";
} catch (\Throwable $e) {
    echo "FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n\n";
}

// 3. Search::queryCandidates
echo "--- 3. Search::queryCandidates() ---\n";
try {
    $candidates = Search::queryCandidates($query, 12, $intent ?? SearchIntent::General);
    echo "Candidates: " . count($candidates) . "\n";
    if (!empty($candidates)) {
        echo "First: " . $candidates[0]->url . "\n";
    }
    echo "\n";
} catch (\Throwable $e) {
    echo "FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n\n";
}

// 4. Full SearchPipeline::run with catch
echo "--- 4. SearchPipeline::run() (with catch) ---\n";
$agent = new AgentManager();
$pipeline = new SearchPipeline($agent);
$emit = function(string $stage, array $data = []) {
    echo "  EMIT: $stage\n";
};
try {
    $result = $pipeline->run($query, [], $emit);
    echo "Evidence: " . strlen($result['evidence'] ?? '') . " chars\n";
    echo "SourceIds: " . implode(', ', $result['sourceIds'] ?? ['NONE']) . "\n";
    echo "SourceUrls: " . count($result['sourceUrls'] ?? []) . "\n";
} catch (\Throwable $e) {
    echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 5. Check Redis keys
echo "\n--- 5. Redis outbound keys ---\n";
try {
    $redis = \App\Cache::getClient();
    $global = $redis->get('outbound:global:next_allowed_at');
    $searxng = $redis->get('outbound:service:searxng:next_allowed_at');
    echo "global next: " . ($global ?: 'null') . " (" . ($global ? (intval($global) - intval(microtime(true)*1000)) . 'ms from now' : 'N/A') . ")\n";
    echo "searxng next: " . ($searxng ?: 'null') . " (" . ($searxng ? (intval($searxng) - intval(microtime(true)*1000)) . 'ms from now' : 'N/A') . ")\n";
} catch (\Throwable $e) {
    echo "FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
