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


        $noSources = [
            ['role' => 'system', 'message' => 'plain tool output, no source markers', 'message_type' => 'data_fetching'],
        ];

        $evicted = [
            ['role' => 'system', 'message' => '<source id="S1">a</source>', 'message_type' => 'data_fetching', 'raw_evicted' => 1],
            ['role' => 'system', 'message' => '<source id="S2">b</source>', 'message_type' => 'data_fetching', 'raw_evicted' => 0],
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

        $out2 = $this->validator->sanitizeCitations(
            'See [S1-C2] and [S1, S3] and [S2-C1, S3-C4].',
            ['S1', 'S2', 'S3']
        );
        $this->test('chunk + comma variants stripped', !str_contains($out2, '[S'));

        $out3 = $this->validator->sanitizeCitations('Paris [S1] is capital.', []);
        $this->test('empty valid set strips citation', !str_contains($out3, '[S1]'));

        // ── cited in prose instead of in brackets ────────────────────────────
        // Measured: "**Caveat on those prices:** S2/S3 are eBay US listings." reached the user with the
        // internal ids intact — brackets were the only shape the sanitizer knew.
        $prose = $this->validator->sanitizeCitations(
            'Caveat on those prices: S2/S3 are eBay US listings.',
            ['S1', 'S2', 'S3']
        );
        $this->test('a slash-joined run of ids is stripped', !preg_match('/\bS\d+\b/', $prose));
        $this->test('and the sentence around it survives', str_contains($prose, 'are eBay US listings'));

        $spoken = $this->validator->sanitizeCitations('See S1, S2 and S3 for the numbers.', ['S1', 'S2', 'S3']);
        $this->test('a spelled-out run is stripped too', !preg_match('/\bS\d+\b/', $spoken));

        $inParens = $this->validator->sanitizeCitations('Prices (S2, S3) are US listings.', ['S1', 'S2', 'S3']);
        $this->test('a parenthesised run leaves no empty parens and no id',
            !preg_match('/\bS\d+\b/', $inParens) && !str_contains($inParens, '('));

        // A lone id is left alone on purpose: these ids are a letter and a digit and collide with real names
        // in this domain. One stray id is a smaller failure than mangling "AWS S3".
        $aws = $this->validator->sanitizeCitations('Upload it to AWS S3 and check.', ['S1', 'S2', 'S3']);
        $this->test('a lone id that is a real name survives', str_contains($aws, 'AWS S3'));
        $cs = $this->validator->sanitizeCitations('CS2 runs faster than CS:GO.', ['S1', 'S2']);
        $this->test('an id inside a longer token is not touched', str_contains($cs, 'CS2'));

        $chunk = $this->validator->sanitizeCitations('The claim S2-C1 is wrong.', ['S1', 'S2']);
        $this->test('a bare chunk reference is stripped', !str_contains($chunk, 'S2-C1'));

        // ── the token the UI badges is kept ──────────────────────────────────
        // #REFID_n is the model-facing citation (see PromptAssemblyService::toRefIds). It survives into the
        // stored answer so the rendered badge can replace it; only a token with nothing behind it goes.
        $kept = $this->validator->sanitizeCitations('Tea helps. #REFID_2', ['S1', 'S2']);
        $this->test('a token for a source this turn showed is kept', str_contains($kept, '#REFID_2'));
        $this->test('and the sentence around it is untouched', str_contains($kept, 'Tea helps.'));

        $dead = $this->validator->sanitizeCitations('Tea helps. #REFID_9', ['S1', 'S2']);
        $this->test('a token with no source behind it is dropped', !str_contains($dead, '#REFID_'));

        $padded = $this->validator->sanitizeCitations('Tea. #REFID_02', ['S1', 'S2']);
        $this->testEq('the number is normalised for the lookup', 'Tea. #REFID_2', $padded);

        $this->testEq('a chunk-suffixed source token is normalised without a dangling chunk',
            'Tea. #REFID_2.', $this->validator->sanitizeCitations('Tea. #REFID_02-C12.', ['S2']));
        $this->testEq('an unknown chunk-suffixed token is removed in full',
            'Tea. .', $this->validator->sanitizeCitations('Tea. #REFID_9-C1.', ['S2']));

        $asToken = $this->validator->sanitizeCitations('Tea. #REFID_2', ['#REFID_1', '#REFID_2']);
        $this->test('an already-tokenised valid list works too', str_contains($asToken, '#REFID_2'));

        // The model does not always write the asked-for shape: "[REFID_1-C2]" in brackets without the hash was
        // measured leaking through every renderer as literal text. Stored text is normalised to the one shape.
        $this->testEq('a bracketed, hash-less token is normalised to the canonical token',
            'Loose leaf tea helps. #REFID_2.', $this->validator->sanitizeCitations('Loose leaf tea helps. [REFID_2-C1].', ['S2']));
        $this->testEq('a bracketed token with no source behind it is removed, brackets and all',
            'Loose leaf tea helps. .', $this->validator->sanitizeCitations('Loose leaf tea helps. [REFID_9-C1].', ['S2']));
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
