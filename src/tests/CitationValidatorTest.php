<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\CitationValidator;
use App\Services\PromptAssemblyService;

class CitationValidatorTest
{
    private CitationValidator $validator;
    private PromptAssemblyService $prompt;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(\App\Database $db)
    {
        $this->validator = new CitationValidator();
        $this->prompt = new PromptAssemblyService($db, __DIR__ . '/../uploads/');
    }

    public function run(): bool
    {
        $this->runExtractVisibleSourceIds();
        $this->runSanitizeCitations();
        $this->runHasHallucinatedCitations();
        $this->runExtractCitations();

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

    // ===================================================================
    // extractVisibleSourceIds — union of sources rendered in the prompt
    // ===================================================================
    private function runExtractVisibleSourceIds(): void
    {
        echo "\n=== PromptAssemblyService::extractVisibleSourceIds ===\n";

        $history = [
            ['role' => 'system', 'message' => '<source id="S1">a</source>', 'message_type' => 'data_fetching'],
            ['role' => 'system', 'message' => '<source id="S2">b</source><source id="S3">c</source>', 'message_type' => 'data_fetching'],
            ['role' => 'user', 'message' => '<source id="S99">not evidence</source>', 'message_type' => 'text'],
            ['role' => 'assistant', 'message' => 'answer', 'message_type' => 'text'],
        ];
        $this->testEq('union across data_fetching rows, skips non-evidence',
            ['S1', 'S2', 'S3'], $this->prompt->extractVisibleSourceIds($history));

        $dup = [
            ['role' => 'system', 'message' => '<source id="S1">a</source>', 'message_type' => 'data_fetching'],
            ['role' => 'system', 'message' => '<source id="S1">again</source>', 'message_type' => 'data_fetching'],
        ];
        $this->testEq('dedupes repeated IDs', ['S1'], $this->prompt->extractVisibleSourceIds($dup));

        $this->testEq('empty history -> empty', [], $this->prompt->extractVisibleSourceIds([]));

        $noSources = [
            ['role' => 'system', 'message' => 'plain tool output, no source markers', 'message_type' => 'data_fetching'],
        ];
        $this->testEq('data_fetching without markers -> empty', [], $this->prompt->extractVisibleSourceIds($noSources));

        $evicted = [
            ['role' => 'system', 'message' => '<source id="S1">a</source>', 'message_type' => 'data_fetching', 'active_context' => 0],
            ['role' => 'system', 'message' => '<source id="S2">b</source>', 'message_type' => 'data_fetching', 'active_context' => 1],
        ];
        $this->testEq('evicted sources excluded from visible union', ['S2'], $this->prompt->extractVisibleSourceIds($evicted));
    }

    // ===================================================================
    // sanitizeCitations — strips valid (known) and hallucinated markers
    // ===================================================================
    private function runSanitizeCitations(): void
    {
        echo "\n=== CitationValidator::sanitizeCitations ===\n";

        $out = $this->validator->sanitizeCitations(
            'Claim one [S1]. Claim two [S9].',
            ['S1', 'S2', 'S3']
        );
        $this->test('valid citation stripped', !str_contains($out, '[S1]'));
        $this->test('hallucinated citation stripped', !str_contains($out, '[S9]'));
        $this->test('non-citation text preserved', str_contains($out, 'Claim one'));

        $out2 = $this->validator->sanitizeCitations(
            'See [S1-C2] and [S1, S3] and [S2-C1, S3-C4].',
            ['S1', 'S2', 'S3']
        );
        $this->test('chunk + comma variants stripped', !str_contains($out2, '[S'));

        $out3 = $this->validator->sanitizeCitations('Paris [S1] is capital.', []);
        $this->test('empty valid set strips citation', !str_contains($out3, '[S1]'));
        $this->test('empty valid set preserves text', str_contains($out3, 'Paris'));
    }

    // ===================================================================
    // hasHallucinatedCitations — visible older citation is NOT hallucinated
    // ===================================================================
    private function runHasHallucinatedCitations(): void
    {
        echo "\n=== CitationValidator::hasHallucinatedCitations ===\n";

        $this->test('visible older citation not hallucinated',
            !$this->validator->hasHallucinatedCitations('Fact [S2].', ['S1', 'S2', 'S3']));
        $this->test('non-visible citation hallucinated',
            $this->validator->hasHallucinatedCitations('Fact [S9].', ['S1', 'S2', 'S3']));
        $this->test('no citations -> not hallucinated',
            !$this->validator->hasHallucinatedCitations('Plain answer.', ['S1']));
        $this->test('empty valid set + citation -> hallucinated',
            $this->validator->hasHallucinatedCitations('Fact [S1].', []));
    }

    // ===================================================================
    // extractCitations — unique IDs referenced in an answer
    // ===================================================================
    private function runExtractCitations(): void
    {
        echo "\n=== CitationValidator::extractCitations ===\n";

        $this->testEq('extracts unique IDs in order',
            ['S1', 'S3'], $this->validator->extractCitations('See [S1] and [S3] and [S1].'));
        $this->testEq('no citations -> empty', [], $this->validator->extractCitations('No citations here.'));
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
}
