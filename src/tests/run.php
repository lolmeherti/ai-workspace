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
require_once __DIR__ . '/SearchMemoriesToolTest.php';
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
require_once __DIR__ . '/EvidenceConsolidationTest.php';
require_once __DIR__ . '/AtomizationPolicyTest.php';
require_once __DIR__ . '/ToolSchemaTest.php';
require_once __DIR__ . '/TodoistConflictDetectionTest.php';
require_once __DIR__ . '/TodoistCreateToolTest.php';
require_once __DIR__ . '/BriefingDataServiceTest.php';
require_once __DIR__ . '/BriefingTriageTest.php';
require_once __DIR__ . '/BriefingExtractorTest.php';
require_once __DIR__ . '/EmailServiceTest.php';
require_once __DIR__ . '/RuntimeSamplingTest.php';
require_once __DIR__ . '/ModelPerformanceReportTest.php';
require_once __DIR__ . '/RateReplyActionTest.php';
require_once __DIR__ . '/ReasoningEffortTest.php';
require_once __DIR__ . '/ExportRenderTest.php';
require_once __DIR__ . '/RedditUrlTest.php';
require_once __DIR__ . '/UrlIndexTest.php';
require_once __DIR__ . '/ToolArgumentTest.php';
require_once __DIR__ . '/SourceFailuresTest.php';
require_once __DIR__ . '/SessionTitleTest.php';
require_once __DIR__ . '/ChunkMapTest.php';
require_once __DIR__ . '/ToolLoopTest.php';
require_once __DIR__ . '/TranscriptFusionTest.php';
require_once __DIR__ . '/VideoWatchRunnerTest.php';
require_once __DIR__ . '/VideoSourcesTest.php';
require_once __DIR__ . '/VideoWatchIngestTest.php';
require_once __DIR__ . '/ContextSourceTextTest.php';
require_once __DIR__ . '/JobQueueTest.php';
require_once __DIR__ . '/EnrichmentJobTest.php';
require_once __DIR__ . '/PendingPromptQueueTest.php';
require_once __DIR__ . '/OwedWorkTest.php';
require_once __DIR__ . '/QueueReconciliationTest.php';
require_once __DIR__ . '/CalculatorTest.php';

use App\Config;
use App\Database;
use App\Tests\MessageAssemblyTest;
use App\Tests\ThoughtExtractionTest;
use App\Tests\MultiQueryTest;
use App\Tests\SourceSequenceTest;
use App\Tests\MemorySelectorTest;
use App\Tests\SearchMemoriesToolTest;
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
use App\Tests\EvidenceConsolidationTest;
use App\Tests\AtomizationPolicyTest;
use App\Tests\ToolSchemaTest;
use App\Tests\TodoistConflictDetectionTest;
use App\Tests\TodoistCreateToolTest;
use App\Tests\BriefingDataServiceTest;
use App\Tests\BriefingTriageTest;
use App\Tests\BriefingExtractorTest;
use App\Tests\EmailServiceTest;
use App\Tests\RuntimeSamplingTest;
use App\Tests\ModelPerformanceReportTest;
use App\Tests\RateReplyActionTest;
use App\Tests\ReasoningEffortTest;
use App\Tests\ExportRenderTest;
use App\Tests\RedditUrlTest;
use App\Tests\UrlIndexTest;
use App\Tests\ToolArgumentTest;
use App\Tests\SourceFailuresTest;
use App\Tests\SessionTitleTest;
use App\Tests\ChunkMapTest;
use App\Tests\ToolLoopTest;
use App\Tests\JobQueueTest;
use App\Tests\EnrichmentJobTest;
use App\Tests\PendingPromptQueueTest;
use App\Tests\OwedWorkTest;
use App\Tests\QueueReconciliationTest;
use App\Tests\CalculatorTest;
use App\Tests\TranscriptFusionTest;
use App\Tests\VideoWatchRunnerTest;
use App\Tests\VideoSourcesTest;
use App\Tests\VideoWatchIngestTest;
use App\Tests\ContextSourceTextTest;

Config::load(__DIR__ . '/..');

// Event attribution: $modelName is process-global, so a suite constructing an AgentManager would tag every
// later warn/error row with a real model and distort the /models.php per-model fault counts.
\App\Logger::clearModelName();

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

echo "\n=== Phase 5b: Search Memories Tool (dedup) Tests ===\n";
$allOk = (new SearchMemoriesToolTest())->run() && $allOk;

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

echo "\n=== Phase 22: Evidence Consolidation Tests ===\n";
$allOk = (new EvidenceConsolidationTest($db))->run() && $allOk;

echo "\n=== Phase 23: Atomization Policy Tests ===\n";
$allOk = (new AtomizationPolicyTest())->run() && $allOk;

echo "\n=== Phase 24: Tool Schema Tests ===\n";
$allOk = (new ToolSchemaTest())->run() && $allOk;

echo "\n=== Phase 25: Todoist Conflict Detection Tests ===\n";
$allOk = (new TodoistConflictDetectionTest())->run() && $allOk;

echo "\n=== Phase 26: Todoist Create Tool Tests ===\n";
$allOk = (new TodoistCreateToolTest($db, __DIR__ . '/../uploads/'))->run() && $allOk;

echo "\n=== Phase 27: Briefing Data Service Tests ===\n";
$allOk = (new BriefingDataServiceTest())->run() && $allOk;

echo "\n=== Phase 28: Briefing Triage Tests ===\n";
$allOk = (new BriefingTriageTest())->run() && $allOk;

echo "\n=== Phase 29: Briefing Extractor Tests ===\n";
$allOk = (new BriefingExtractorTest())->run() && $allOk;

echo "\n=== Phase 30: Email Service Error Classification Tests ===\n";
$allOk = (new EmailServiceTest())->run() && $allOk;

echo "\n=== Phase 31: Runtime Sampling / Reasoning Policy Tests ===\n";
$allOk = (new RuntimeSamplingTest())->run() && $allOk;

echo "\n=== Phase 32: Model Performance Report Tests ===\n";
$allOk = (new ModelPerformanceReportTest())->run() && $allOk;

echo "\n=== Phase 33: Rate Reply Action Tests ===\n";
$allOk = (new RateReplyActionTest())->run() && $allOk;

echo "\n=== Phase 34: Reasoning Effort Tests ===\n";
$allOk = (new ReasoningEffortTest())->run() && $allOk;

echo "\n=== Phase 35: Conversation Export Tests ===\n";
$allOk = (new ExportRenderTest())->run() && $allOk;

echo "\n=== Phase 36: Reddit URL Policy Tests ===\n";
$allOk = (new RedditUrlTest())->run() && $allOk;

echo "\n=== Phase 37: URL Index Tests (pasted text) ===\n";
$allOk = (new UrlIndexTest())->run() && $allOk;

echo "\n=== Phase 38: Tool Argument Normalisation ===\n";
$allOk = (new ToolArgumentTest($db))->run() && $allOk;

echo "\n=== Phase 39: Source Outcomes ===\n";
$allOk = (new SourceFailuresTest())->run() && $allOk;

echo "\n=== Phase 40: Session Title Tests ===\n";
$allOk = (new SessionTitleTest($db))->run() && $allOk;

echo "\n=== Phase 41: Transcript Fusion (video watcher) ===\n";
$allOk = (new TranscriptFusionTest())->run() && $allOk;

echo "\n=== Phase 42: Video Watch Stop Path ===\n";
$allOk = (new VideoWatchRunnerTest())->run() && $allOk;

echo "\n=== Phase 43: Video Source Map ===\n";
$allOk = (new VideoSourcesTest())->run() && $allOk;
echo "\n=== Phase 44: Video Turn ===\n";
$allOk = (new VideoWatchIngestTest())->run() && $allOk;

echo "\n=== Phase 45: Context Data source text ===\n";
$allOk = (new ContextSourceTextTest())->run() && $allOk;

echo "\n=== Phase 46: Evidence Map + Unit Reads ===\n";
$allOk = (new ChunkMapTest($db))->run() && $allOk;

echo "\n=== Phase 47: Tool Loop Mechanics ===\n";
$allOk = (new ToolLoopTest($db))->run() && $allOk;

echo "\n=== Phase 48: Background Job Queue ===\n";
$allOk = (new JobQueueTest())->run() && $allOk;

echo "\n=== Phase 49: Enrichment job body ===\n";
$allOk = (new EnrichmentJobTest($db))->run() && $allOk;

echo "\n=== Phase 50: Held messages + stop control ===\n";
$allOk = (new PendingPromptQueueTest($db))->run() && $allOk;

echo "\n=== Phase 51: The terminal link + owed work ===\n";
$allOk = (new OwedWorkTest($db))->run() && $allOk;

echo "\n=== Phase 52: Boot reconciliation ===\n";
$allOk = (new QueueReconciliationTest($db))->run() && $allOk;

echo "\n=== Phase 53: The calculate tool ===\n";
$allOk = (new CalculatorTest())->run() && $allOk;

echo "\n" . str_repeat('=', 55) . "\n";
echo $allOk ? "ALL PHASES PASSED\n" : "SOME PHASES FAILED\n";

exit($allOk ? 0 : 1);
