<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database;
use App\Actions\File\FileSyncAction;
use App\Services\FileIngestor;

class ReindexStubDatabase extends Database
{
    public array $updates = [];
    public array $inserts = [];
    public array $queries = [];

    public function __construct()
    {
        // no parent call — never touch MySQL
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        return [];
    }

    public function update(string $table, array $data, array $conditions): bool
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'conditions' => $conditions];
        return true;
    }

    public function insert(string $table, array $data): bool
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return true;
    }
}

class ReindexStubIngestor extends FileIngestor
{
    public array $record = [];
    public bool $throwOnIngest = false;

    public function __construct()
    {
        // no parent call — never construct an AgentManager
    }

    public function ingest(string $path, string $mimeType, ?string $originalName): array
    {
        if ($this->throwOnIngest) {
            throw new \RuntimeException('simulated ingest failure');
        }
        return $this->record;
    }
}

/**
 * Tests the safety-critical re-index unit (FileSyncAction::reindexExisting):
 * stamping only on success, preserving id/physical_name (no delete/re-insert),
 * and leaving a row stale when ingestion fails so a later run can resume.
 */
class FileSyncReindexTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runReindexSuccess();
        $this->runReindexFailure();

        echo "\n" . str_repeat('=', 55) . "\n";
        printf("Results: %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);

        if (!empty($this->failures)) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f['label']}\n";
            }
            echo "\nSOME TESTS FAILED\n";
        } else {
            echo "ALL TESTS PASSED\n";
        }

        return empty($this->failures);
    }

    private function test(string $label, bool $condition): void
    {
        printf("  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
        if (!$condition) {
            $this->failures[] = ['label' => $label];
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (!$ok) {
            $this->failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function makeAction(ReindexStubDatabase $db, ReindexStubIngestor $ingestor): FileSyncAction
    {
        $action = new FileSyncAction($db);
        $prop = new \ReflectionProperty(FileSyncAction::class, 'ingestor');
        $prop->setValue($action, $ingestor);
        return $action;
    }

    private function reindexExisting(FileSyncAction $action, int $id, string $path, string $filename): bool
    {
        $method = new \ReflectionMethod(FileSyncAction::class, 'reindexExisting');
        return (bool) $method->invoke($action, $id, $path, $filename);
    }

    private function runReindexSuccess(): void
    {
        echo "\n=== re-index success stamps + preserves identity ===\n";

        $db = new ReindexStubDatabase();
        $ingestor = new ReindexStubIngestor();
        $ingestor->record = [
            'title' => 'Blood Test Results',
            'searchable_text' => 'english normalized text',
            'search_entities' => ['hemoglobin', '12.03.2024'],
            'extracted_text' => '',
        ];

        $action = $this->makeAction($db, $ingestor);
        $ok = $this->reindexExisting($action, 42, '/tmp/blood.png', 'blood.png');

        $this->testEq('reindexExisting returns true on success', true, $ok);
        $this->testEq('exactly one UPDATE issued', 1, count($db->updates));
        $this->testEq('update targets uploaded_files', 'uploaded_files', $db->updates[0]['table']);
        $this->testEq('update condition is id = 42', ['id' => 42], $db->updates[0]['conditions']);
        $this->testEq('update stamps CURRENT_INDEX_VERSION', Config::CURRENT_INDEX_VERSION, $db->updates[0]['data']['search_index_version']);
        $this->testEq('update writes generated_title', 'Blood Test Results', $db->updates[0]['data']['generated_title']);
        $this->testEq('update writes searchable_text', 'english normalized text', $db->updates[0]['data']['searchable_text']);
        $this->testEq('update writes search_entities JSON', json_encode(['hemoglobin', '12.03.2024']), $db->updates[0]['data']['search_entities']);
        $this->testEq('no INSERT issued (id preserved, no re-insert)', 0, count($db->inserts));
        $this->test('physical_name not in update (preserved on disk)', !array_key_exists('physical_name', $db->updates[0]['data']));
    }

    private function runReindexFailure(): void
    {
        echo "\n=== re-index failure leaves row stale (resumable) ===\n";

        $db = new ReindexStubDatabase();
        $ingestor = new ReindexStubIngestor();
        $ingestor->throwOnIngest = true;

        $action = $this->makeAction($db, $ingestor);
        $ok = $this->reindexExisting($action, 42, '/tmp/broken.png', 'broken.png');

        $this->testEq('reindexExisting returns false on failure', false, $ok);
        $this->testEq('no UPDATE issued on failure (row stays stale)', 0, count($db->updates));
        $this->testEq('no INSERT issued on failure', 0, count($db->inserts));
    }
}
