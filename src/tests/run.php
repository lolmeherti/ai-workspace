<?php
/**
 * Test runner — runs all test suites sequentially.
 *
 * Run: docker exec ai_php_web php /var/www/html/tests/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/MessageAssemblyTest.php';
require_once __DIR__ . '/ThoughtExtractionTest.php';
require_once __DIR__ . '/MultiQueryTest.php';
require_once __DIR__ . '/SourceSequenceTest.php';
require_once __DIR__ . '/MemorySelectorTest.php';
require_once __DIR__ . '/JobStateMachineTest.php';
require_once __DIR__ . '/JobMatcherTest.php';
require_once __DIR__ . '/JobRepositoryTest.php';
require_once __DIR__ . '/JobParserTest.php';
require_once __DIR__ . '/JobEvaluatorTest.php';
require_once __DIR__ . '/TemplateExpanderTest.php';
require_once __DIR__ . '/JobAdapterTest.php';
require_once __DIR__ . '/FileIngestorTest.php';
require_once __DIR__ . '/FileRetrieverTest.php';
require_once __DIR__ . '/FileSyncReindexTest.php';
require_once __DIR__ . '/ModelLockTest.php';
require_once __DIR__ . '/FileRetrievalEvalTest.php';
require_once __DIR__ . '/CitationValidatorTest.php';
require_once __DIR__ . '/ContextBudgetTest.php';
require_once __DIR__ . '/CondensationTest.php';
require_once __DIR__ . '/PromptInjectionFilterTest.php';

use App\Config;
use App\Database;
use App\Tests\MessageAssemblyTest;
use App\Tests\ThoughtExtractionTest;
use App\Tests\MultiQueryTest;
use App\Tests\SourceSequenceTest;
use App\Tests\MemorySelectorTest;
use App\Tests\JobStateMachineTest;
use App\Tests\JobMatcherTest;
use App\Tests\JobRepositoryTest;
use App\Tests\JobParserTest;
use App\Tests\JobEvaluatorTest;
use App\Tests\TemplateExpanderTest;
use App\Tests\JobAdapterTest;
use App\Tests\FileIngestorTest;
use App\Tests\FileRetrieverTest;
use App\Tests\FileSyncReindexTest;
use App\Tests\ModelLockTest;
use App\Tests\FileRetrievalEvalTest;
use App\Tests\CitationValidatorTest;
use App\Tests\ContextBudgetTest;
use App\Tests\CondensationTest;
use App\Tests\PromptInjectionFilterTest;

Config::load(__DIR__ . '/..');

$db = null;
try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Is MySQL running? Tests need the database for test construction.\n");
    exit(1);
}

$allOk = true;

echo "=== Phase 1: Message Assembly Tests ===\n";
$allOk = (new MessageAssemblyTest($db, __DIR__ . '/../uploads/'))->run() && $allOk;

echo "\n=== Phase 2: Thought Extraction Tests ===\n";
$allOk = (new ThoughtExtractionTest())->run() && $allOk;

echo "\n=== Phase 3: Multi-Query Tests ===\n";
$allOk = (new MultiQueryTest())->run() && $allOk;

echo "\n=== Phase 4: Source Sequence Tests ===\n";
$allOk = (new SourceSequenceTest($db))->run() && $allOk;

echo "\n=== Phase 5: Memory Selector Tests ===\n";
$allOk = (new MemorySelectorTest($db))->run() && $allOk;

echo "\n=== Phase 6: Job State Machine Tests ===\n";
$allOk = (new JobStateMachineTest())->run() && $allOk;

echo "\n=== Phase 7: Job Matcher Tests ===\n";
$allOk = (new JobMatcherTest())->run() && $allOk;

echo "\n=== Phase 8: Job Repository Tests ===\n";
$allOk = (new JobRepositoryTest($db))->run() && $allOk;

echo "\n=== Phase 9: Job Parser Tests ===\n";
$allOk = (new JobParserTest())->run() && $allOk;

echo "\n=== Phase 10: Job Evaluator Tests ===\n";
$allOk = (new JobEvaluatorTest())->run() && $allOk;

echo "\n=== Phase 11: Template Expander Tests ===\n";
$allOk = (new TemplateExpanderTest())->run() && $allOk;

echo "\n=== Phase 12: Job Adapter Tests ===\n";
$allOk = (new JobAdapterTest())->run() && $allOk;

echo "\n=== Phase 13: File Ingestor Tests ===\n";
$allOk = (new FileIngestorTest())->run() && $allOk;

echo "\n=== Phase 14: File Retriever Tests ===\n";
$allOk = (new FileRetrieverTest())->run() && $allOk;

echo "\n=== Phase 15: Model Lock Tests ===\n";
$allOk = (new ModelLockTest())->run() && $allOk;

echo "\n=== Phase 16: File Sync Re-index Tests ===\n";
$allOk = (new FileSyncReindexTest())->run() && $allOk;

echo "\n=== Phase 17: File Retrieval Evaluation ===\n";
$allOk = (new FileRetrievalEvalTest())->run() && $allOk;

echo "\n=== Phase 18: Citation Validator Tests ===\n";
$allOk = (new CitationValidatorTest($db))->run() && $allOk;

echo "\n=== Phase 19: Context Budget Tests ===\n";
$allOk = (new ContextBudgetTest($db))->run() && $allOk;

echo "\n=== Phase 20: Condensation Tests ===\n";
$allOk = (new CondensationTest($db))->run() && $allOk;

echo "\n=== Phase 21: Prompt Injection Filter Tests ===\n";
$allOk = (new PromptInjectionFilterTest())->run() && $allOk;

echo "\n" . str_repeat('=', 55) . "\n";
echo $allOk ? "ALL PHASES PASSED\n" : "SOME PHASES FAILED\n";

exit($allOk ? 0 : 1);
