<?php
/**
 * Test runner — runs all test suites sequentially.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ParserTest.php';
require_once __DIR__ . '/MessageAssemblyTest.php';
require_once __DIR__ . '/ThoughtExtractionTest.php';
require_once __DIR__ . '/MultiQueryTest.php';
require_once __DIR__ . '/SearchPipelineTest.php';

use App\Config;
use App\Database;
use App\AgentManager;
use App\Services\ToolExecutionService;
use App\Tests\ParserTest;
use App\Tests\MessageAssemblyTest;
use App\Tests\ThoughtExtractionTest;
use App\Tests\MultiQueryTest;
use App\Tests\SearchPipelineTest;

Config::load(__DIR__ . '/..');

$db = null;
try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Is MySQL running? Tests need the database for test construction.\n");
    exit(1);
}

$agent = new AgentManager();
$service = new ToolExecutionService($db, $agent, __DIR__ . '/../uploads/');

$allOk = true;

echo "=== Phase 1: Parser Tests ===\n";
$allOk = (new ParserTest($service))->run() && $allOk;

echo "\n=== Phase 2: Message Assembly Tests ===\n";
$allOk = (new MessageAssemblyTest($db, $agent, __DIR__ . '/../uploads/'))->run() && $allOk;

echo "\n=== Phase 3: Thought Extraction Tests ===\n";
$allOk = (new ThoughtExtractionTest())->run() && $allOk;

echo "\n=== Phase 4: Multi-Query Tests ===\n";
$allOk = (new MultiQueryTest())->run() && $allOk;

echo "\n=== Phase 5: Search Pipeline Tests ===\n";
$allOk = (new SearchPipelineTest($db, $agent, __DIR__ . '/../uploads/'))->run() && $allOk;

echo "\n" . str_repeat('=', 55) . "\n";
echo $allOk ? "ALL PHASES PASSED\n" : "SOME PHASES FAILED\n";

exit($allOk ? 0 : 1);
