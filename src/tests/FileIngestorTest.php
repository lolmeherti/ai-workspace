<?php

declare(strict_types=1);

namespace App\Tests;

use App\AgentManager;
use App\Services\FileIngestor;

class StubAgentManager extends AgentManager
{
    public array $responses = [];
    public array $calls = [];

    public function chat(array $messages, bool $stream = true, callable $streamCallback = null, ?float $temperature = null, ?string $purpose = null): string
    {
        $this->calls[] = ['temperature' => $temperature];
        return array_shift($this->responses) ?? '';
    }
}

class FileIngestorTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private string $tmpDir;

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/file_ingestor_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    public function run(): bool
    {
        $this->runImagePath();
        $this->runImageParseFailure();
        $this->runEmptyExtractionRoutesToVision();
        $this->runDocumentEnglishPath();
        $this->runDocumentGermanPath();
        $this->runDocumentTranslationFailure();
        $this->runExtractionCap();

        $this->cleanup();

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

    private function writeFixture(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function cleanup(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function runImagePath(): void
    {
        echo "\n=== Image structured call ===\n";

        $stub = new StubAgentManager();
        $stub->responses = [
            json_encode([
                'generated_title' => 'Blood Test Results',
                'visible_text_original' => 'Blutbild vom 12.03.2024',
                'visible_text_english' => 'Blood count from 12.03.2024',
                'description_english' => 'A medical blood test report listing hemoglobin and leukocyte values.',
                'entities' => ['hemoglobin', 'leukocyte', '12.03.2024'],
            ]),
        ];
        $ingestor = new FileIngestor($stub);
        $path = $this->writeFixture('img.png', 'fakeimagebytes');
        $result = $ingestor->ingest($path, 'image/png', 'blood.png');

        $this->testEq('image title from structured call', 'Blood Test Results', $result['title']);
        $this->test('searchable_text contains visible_text_original', str_contains($result['searchable_text'], 'Blutbild vom 12.03.2024'));
        $this->test('searchable_text contains visible_text_english', str_contains($result['searchable_text'], 'Blood count from 12.03.2024'));
        $this->test('searchable_text contains description', str_contains($result['searchable_text'], 'A medical blood test report'));
        $this->test('searchable_text contains joined entities', str_contains($result['searchable_text'], 'hemoglobin leukocyte 12.03.2024'));
        $this->testEq('search_entities', ['hemoglobin', 'leukocyte', '12.03.2024'], $result['search_entities']);
    }

    private function runImageParseFailure(): void
    {
        echo "\n=== Image parse failure fallback ===\n";

        $stub = new StubAgentManager();
        $stub->responses = ['not a json response at all'];
        $ingestor = new FileIngestor($stub);
        $path = $this->writeFixture('img2.png', 'fakeimagebytes');
        $result = $ingestor->ingest($path, 'image/png', 'scan.png');

        $this->testEq('parse failure falls back to filename title', 'image, scan', $result['title']);
        $this->testEq('parse failure searchable_text falls back to filename', 'scan.png', $result['searchable_text']);
        $this->testEq('parse failure yields empty entities', [], $result['search_entities']);
    }

    private function runEmptyExtractionRoutesToVision(): void
    {
        echo "\n=== Empty extraction routes to vision ===\n";

        $stub = new StubAgentManager();
        $stub->responses = [
            json_encode([
                'generated_title' => 'Scanned Document',
                'visible_text_original' => 'Unleserlich',
                'visible_text_english' => 'Illegible',
                'description_english' => 'A scanned page.',
                'entities' => [],
            ]),
        ];
        $ingestor = new FileIngestor($stub);
        $path = $this->writeFixture('doc.xyz', 'unparseable bytes');
        $result = $ingestor->ingest($path, 'application/octet-stream', 'doc.xyz');

        $this->testEq('empty extraction routes to vision (title from image call)', 'Scanned Document', $result['title']);
    }

    private function runDocumentEnglishPath(): void
    {
        echo "\n=== Document always normalized to English ===\n";

        $stub = new StubAgentManager();
        $stub->responses = [
            'Quarterly Financial Report',
            'The quarterly financial report for the year 2024.',
        ];
        $ingestor = new FileIngestor($stub);
        $content = 'This is a quarterly financial report for the year 2024. It contains revenue figures and expenses for the company.';
        $path = $this->writeFixture('report.txt', $content);
        $result = $ingestor->ingest($path, 'text/plain', 'report.txt');

        $this->testEq('document title from title call', 'Quarterly Financial Report', $result['title']);
        $this->testEq('searchable_text is the English output of the normalization call', 'The quarterly financial report for the year 2024.', $result['searchable_text']);
        $this->testEq('document search_entities empty (decision 1A)', [], $result['search_entities']);
        $this->testEq('two LLM calls (title + English normalization)', 2, count($stub->calls));
    }

    private function runDocumentGermanPath(): void
    {
        echo "\n=== German document normalized to English ===\n";

        $stub = new StubAgentManager();
        $stub->responses = [
            'Ärztlicher Befund',
            'This is the English translation of the medical report.',
        ];
        $ingestor = new FileIngestor($stub);
        $content = 'Dies ist ein ärztlicher Befund über den Patienten. Der Bericht enthält Blutwerte und Laborergebnisse.';
        $path = $this->writeFixture('befund.txt', $content);
        $result = $ingestor->ingest($path, 'text/plain', 'befund.txt');

        $this->testEq('German document title', 'Ärztlicher Befund', $result['title']);
        $this->testEq('German document searchable_text is English', 'This is the English translation of the medical report.', $result['searchable_text']);
        $this->testEq('extracted_text preserved as original (for sidecar)', $content, $result['extracted_text']);
        $this->testEq('two LLM calls (title + English normalization)', 2, count($stub->calls));
    }

    private function runDocumentTranslationFailure(): void
    {
        echo "\n=== Document translation failure ===\n";

        $stub = new StubAgentManager();
        $stub->responses = ['Report Title', ''];
        $ingestor = new FileIngestor($stub);
        $content = 'Dies ist ein deutscher Befund.';
        $path = $this->writeFixture('fail.txt', $content);
        $result = $ingestor->ingest($path, 'text/plain', 'fail.txt');

        $this->testEq('title still produced', 'Report Title', $result['title']);
        $this->testEq('failed translation yields empty searchable_text (original not indexed)', '', $result['searchable_text']);
    }

    private function runExtractionCap(): void
    {
        echo "\n=== 40K extraction cap ===\n";

        $stub = new StubAgentManager();
        $stub->responses = ['Long Document', 'english translation'];
        $ingestor = new FileIngestor($stub);

        $over = $this->writeFixture('over.txt', str_repeat('word ', 12000));
        $result = $ingestor->ingest($over, 'text/plain', 'over.txt');
        $this->testEq('over-cap extraction truncated to 40000 chars', 40000, mb_strlen($result['extracted_text']));

        $stub2 = new StubAgentManager();
        $stub2->responses = ['Short Document', 'english translation'];
        $ingestor2 = new FileIngestor($stub2);

        $under = 'A short three-page document that stays under the cap.';
        $path2 = $this->writeFixture('under.txt', $under);
        $result2 = $ingestor2->ingest($path2, 'text/plain', 'under.txt');
        $this->testEq('under-cap extraction not truncated', $under, $result2['extracted_text']);
    }
}
