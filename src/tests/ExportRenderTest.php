<?php

declare(strict_types=1);

namespace App\Tests;

use App\Actions\Chat\ChatExportAction;

/**
 * Stands in for App\Database so attachment enrichment can be exercised without a
 * live MySQL connection: only the uploaded_files lookup is answered.
 */
class ExportStorageStub extends \App\Database
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public function query(string $sql, array $params = []): array
    {
        $needle = (string) ($params[0] ?? '');
        $matches = [];
        foreach ($this->rows as $row) {
            if (($row['original_name'] ?? '') === $needle || ($row['physical_name'] ?? '') === $needle) {
                $matches[] = $row;
            }
        }
        return $matches;
    }
}

class ExportRenderTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runClassification();
        $this->runToggles();
        $this->runAttachmentEnrichment();
        $this->runTextRender();
        $this->runJsonRender();

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
            $this->failures[] = ['label' => $label];
            printf("        expected: %s\n", var_export($expected, true));
            printf("        actual:   %s\n", var_export($actual, true));
            $this->failed++;
        } else {
            $this->passed++;
        }
    }

    private function call(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    private function action(): ChatExportAction
    {
        return new ChatExportAction(new \App\Repositories\ChatSessionRepository(null));
    }

    private function actionWithFiles(array $rows, string $uploadDir): ChatExportAction
    {
        return new ChatExportAction(
            new \App\Repositories\ChatSessionRepository(null),
            new ExportStorageStub($rows),
            $uploadDir
        );
    }

    /**
     * The flag set that reproduces the old "everything on" defaults, now sent
     * explicitly: the whole-panel tests need the context groups and reasoning
     * present, and the panel is what sends these flags in production.
     *
     * @return array<string, string>
     */
    private function defaultsOn(): array
    {
        return ['thoughts' => '1', 'citations' => '1', 'web' => '1', 'files' => '1', 'personal' => '1'];
    }

    /**
     * The previous "everything on" baseline plus the flag under test — partial flag
     * sets would otherwise drop the context records these assertions index into.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function with(array $overrides): array
    {
        return array_merge($this->defaultsOn(), $overrides);
    }

    /**
     * @param array<string, string> $get
     * @return array{records: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function build(array $get, ?array $rows = null): array
    {
        return $this->buildWith($this->action(), $get, $rows);
    }

    /**
     * @param array<string, string> $get
     * @return array{records: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildWith(ChatExportAction $action, array $get, ?array $rows = null): array
    {
        $_GET = array_merge(['session_id' => '7'], $get);
        $options = $this->call($action, 'readOptions');
        $result = $this->call($action, 'collectRecords', [$rows ?? $this->fixtureRows(), $options]);
        $meta = $this->call($action, 'buildMeta', [
            ['id' => 7, 'title' => 'Flight prices', 'created_at' => '2026-09-28 09:12:00'],
            $result,
            $options,
        ]);
        return ['records' => $result['records'], 'meta' => $meta, 'counts' => $result['counts'], 'omitted' => $result['omitted']];
    }

    /**
     * Minimal session: one user turn plus one file/image context row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachmentRows(string $label, string $message): array
    {
        return [
            [
                'id' => 1, 'role' => 'user', 'message' => 'take a look at this',
                'message_type' => 'text', 'tool_name' => null, 'search_query' => null,
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:12:00',
            ],
            [
                'id' => 2, 'role' => 'system', 'message' => $message,
                'message_type' => 'data_fetching', 'tool_name' => 'file', 'search_query' => $label,
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:12:05',
            ],
        ];
    }

    private function fixtureRows(): array
    {
        return [
            [
                'id' => 1, 'role' => 'user', 'message' => 'What is the cheapest flight to Helsinki?',
                'message_type' => 'text', 'tool_name' => null, 'search_query' => null,
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:12:00',
            ],
            [
                'id' => 2, 'role' => 'system', 'message' => '<source id="S1"><title>Ryanair deals</title><domain>ryanair.com</domain><chunk id="S1-C1">Vienna to Helsinki from 39 EUR.</chunk></source>',
                'message_type' => 'data_fetching', 'tool_name' => 'search_web', 'search_query' => 'vienna helsinki flights',
                'source_map' => json_encode(['S1' => ['url' => 'https://ryanair.com/deals', 'title' => 'Ryanair deals', 'domain' => 'ryanair.com']]),
                'perf_metrics' => null, 'model' => null, 'image_path' => null, 'created_at' => '2026-09-28 09:12:05',
            ],
            [
                'id' => 3, 'role' => 'system', 'message' => 'Full extracted document text of the travel policy.',
                'message_type' => 'data_fetching', 'tool_name' => 'file', 'search_query' => 'travel-policy.pdf',
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:12:06',
            ],
            [
                'id' => 4, 'role' => 'assistant',
                'message' => '<|channel|>thoughtCheck the fare rules<channel|>The cheapest is 39 EUR.<|channel|>thoughtConfirm baggage<channel|>Baggage is extra.',
                'message_type' => 'text', 'tool_name' => null, 'search_query' => null,
                'source_map' => json_encode(['S1' => ['url' => 'https://ryanair.com/deals', 'title' => 'Ryanair deals', 'domain' => 'ryanair.com']]),
                'perf_metrics' => json_encode([
                    'total_ms' => 21000, 'ttft_ms' => 1200,
                    'calls' => [
                        ['purpose' => 'firstpass', 'elapsed_ms' => 4000, 'prompt_ms' => 3000, 'prompt_n' => 1000, 'cache_n' => 500, 'content_tok' => 40],
                        ['purpose' => 'answer', 'elapsed_ms' => 17000, 'prompt_ms' => 1000, 'prompt_n' => 2000, 'cache_n' => 1400, 'content_tok' => 120, 'pred_tps' => 30],
                    ],
                ]),
                'model' => 'qwen35', 'image_path' => null, 'created_at' => '2026-09-28 09:13:00',
            ],
            [
                'id' => 5, 'role' => 'assistant', 'message' => "SUMMARY OF PREVIOUS CONVERSATION:\nEarlier fare research.",
                'message_type' => 'condensation_summary', 'tool_name' => null, 'search_query' => null,
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:14:00',
            ],
            [
                'id' => 6, 'role' => 'assistant', 'message' => '{"tool":"search_web","query":"flights"}',
                'message_type' => 'tool_call', 'tool_name' => null, 'search_query' => null,
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:14:30',
            ],
            [
                'id' => 7, 'role' => 'system', 'message' => 'Dentist appointment on 2026-06-20 14:00',
                'message_type' => 'data_fetching', 'tool_name' => 'search_calendar', 'search_query' => 'upcoming appointments',
                'source_map' => null, 'perf_metrics' => null, 'model' => null,
                'image_path' => null, 'created_at' => '2026-09-28 09:15:00',
            ],
        ];
    }

    private function runClassification(): void
    {
        echo "\n=== classification and manifest ===\n";

        $built = $this->build($this->defaultsOn());
        $counts = $built['counts'];

        $this->testEq('user rows counted', 1, $counts['user']);
        $this->testEq('assistant rows counted', 1, $counts['assistant']);
        $this->testEq('context rows counted', 3, $counts['context']);
        $this->testEq('condensation summary counted', 1, $counts['summary']);
        $this->testEq('legacy tool_call row omitted', 1, $built['omitted']['other']);
        $this->testEq('record order preserved', [1, 2, 3, 4, 5, 7], array_column($built['records'], 'id'));

        $groups = array_map(fn($r) => $r['group'] ?? null, array_values(array_filter($built['records'], fn($r) => $r['kind'] === 'context')));
        $this->testEq('web row grouped as web', 'web', $groups[0]);
        $this->testEq('attachment row grouped as files', 'files', $groups[1]);
        $this->testEq('calendar row grouped as personal data', 'personal', $groups[2]);

        $this->testEq('title carried into meta', 'Flight prices', $built['meta']['title']);
        $this->testEq('models collected from assistant rows', ['qwen35'], $built['meta']['models']);
    }

    private function runToggles(): void
    {
        echo "\n=== toggles ===\n";

        // Nothing is on unless it is asked for: no flags at all = plain transcript.
        $bare = $this->build([]);
        $bareAssistant = array_values(array_filter($bare['records'], fn($r) => ($r['kind'] ?? '') === 'assistant'))[0];
        $this->test('bare request: no reasoning', !isset($bareAssistant['reasoning']));
        $this->test('bare request: no citations', !isset($bareAssistant['sources']));
        $this->test('bare request: no metrics', !isset($bareAssistant['metrics']));
        $this->testEq('bare request: no context records', 0, $bare['counts']['context']);
        $this->testEq('bare request: groups counted as filtered', 3, $bare['omitted']['context']);
        $this->testEq('bare request: user turn kept', 1, $bare['counts']['user']);
        $this->testEq('bare request: answer kept', 1, $bare['counts']['assistant']);

        $built = $this->build($this->defaultsOn());
        $assistant = $built['records'][3];

        $this->testEq('reasoning included when enabled', "Check the fare rules\n\nConfirm baggage", $assistant['reasoning']);
        $this->testEq('content has every part', 'The cheapest is 39 EUR.Baggage is extra.', $assistant['text']);
        $this->test('content free of thought tags', !str_contains($assistant['text'], 'thought'));
        $this->test('citations included when enabled', isset($assistant['sources']));
        $this->testEq('citation url preserved', 'https://ryanair.com/deals', $assistant['sources'][0]['url']);
        $this->test('metrics off unless requested', !isset($assistant['metrics']));

        $off = $this->build($this->with(['thoughts' => '0']));
        $this->test('thoughts off drops reasoning', !isset($off['records'][3]['reasoning']));
        $this->testEq('thoughts off keeps clean content', 'The cheapest is 39 EUR.Baggage is extra.', $off['records'][3]['text']);

        $metrics = $this->build($this->with(['metrics' => '1']));
        $this->test('metrics on present', isset($metrics['records'][3]['metrics']));
        $this->testEq('metrics cache percent from answer call', 70, $metrics['records'][3]['metrics']['cache_percent']);
        $this->testEq('metrics call chain length', 2, count($metrics['records'][3]['metrics']['calls']));

        $noCitations = $this->build($this->with(['citations' => '0']));
        $this->test('citations off drops source list', !isset($noCitations['records'][3]['sources']));

        $noWeb = $this->build($this->with(['web' => '0', 'citations' => '0']));
        $this->testEq('web off removes the web record', 2, $noWeb['counts']['context']);
        $this->testEq('web off counted as filtered', 1, $noWeb['omitted']['context']);
        $this->test('web off drops the record from output', !str_contains($this->call($this->action(), 'renderText', [$noWeb['meta'], $noWeb['records']]), 'ryanair.com'));

        $noFiles = $this->build($this->with(['files' => '0', 'personal' => '0']));
        $this->testEq('only the web record survives', 1, $noFiles['counts']['context']);
        $this->testEq('file and personal rows counted as filtered', 2, $noFiles['omitted']['context']);
        $survivors = array_map(fn($r) => $r['group'], array_values(array_filter($noFiles['records'], fn($r) => $r['kind'] === 'context')));
        $this->testEq('surviving context record is the web one', ['web'], $survivors);

        // A group switch is the whole payload: there is no "names but not contents"
        // mode, so switching a group on always travels with its text.
        $grouped = $this->build($this->defaultsOn());
        $file = array_values(array_filter($grouped['records'], fn($r) => ($r['group'] ?? '') === 'files'))[0];
        $this->testEq('files group carries the stored text', 'Full extracted document text of the travel policy.', $file['evidence']);
        $this->test('no withheld flag on a group record', !isset($file['withheld']) && !isset($file['content_missing']));
        $this->testEq('attachment char count kept', strlen('Full extracted document text of the travel policy.'), $file['chars']);

        $webRecord = array_values(array_filter($grouped['records'], fn($r) => ($r['group'] ?? '') === 'web'))[0];
        $this->test('web group keeps chunks as a list', is_array($webRecord['evidence']));
        $this->testEq('web group carries page text', 'Vienna to Helsinki from 39 EUR.', $webRecord['evidence'][0]['text']);

        // A stored record with no text at all still exports under its name, flagged.
        $noTextRow = $this->build($this->defaultsOn(), $this->attachmentRows('empty_scan.png', ''));
        $this->test('record without stored text flagged content-missing', ($noTextRow['records'][1]['content_missing'] ?? false) === true);
        $this->test('content-missing record keeps its name', str_contains($this->call($this->action(), 'renderText', [$noTextRow['meta'], $noTextRow['records']]), '[file] empty_scan.png'));
    }

    private function runAttachmentEnrichment(): void
    {
        echo "\n=== attachment enrichment (images) ===\n";

        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/localsy-export-' . getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/phys_real.png', 'x');

        $imageRows = [[
            'original_name' => 'pasted_image.png',
            'physical_name' => 'phys_real.png',
            'file_type' => 'image',
            'searchable_text' => 'Vienna to Helsinki boarding pass, gate C12',
        ]];

        $photo = $this->buildWith(
            $this->actionWithFiles($imageRows, $dir),
            $this->defaultsOn(),
            $this->attachmentRows('pasted_image.png', 'Vienna to Helsinki boarding pass, gate C12')
        );
        $photoRecord = $photo['records'][1];
        $this->testEq('image attachment typed as image', 'image', $photoRecord['attachment_type'] ?? null);
        $this->testEq('stored path kept app-relative', 'uploads/phys_real.png', $photoRecord['attachment_path'] ?? null);
        $this->test('file found on disk', $photoRecord['attachment_present'] === true);
        $this->test('transcribed image is not degraded', !isset($photoRecord['degraded']));

        $photoText = $this->call($this->actionWithFiles($imageRows, $dir), 'renderText', [$photo['meta'], $photo['records']]);
        $photoChars = number_format(strlen('Vienna to Helsinki boarding pass, gate C12'));
        $this->test('typed label in text', str_contains($photoText, '[image] pasted_image.png (' . $photoChars . ' chars)'));
        $this->test('transcribed image exports its text', str_contains($photoText, 'Vienna to Helsinki boarding pass, gate C12'));
        $this->test('image text carries no local path', !str_contains($photoText, 'attach manually') && !str_contains($photoText, 'uploads/phys_real.png'));

        // The file itself going missing does not lose the text: it lives in the record.
        $goneRows = [[
            'original_name' => 'deleted_report.pdf',
            'physical_name' => 'phys_deleted_report.pdf',
            'file_type' => 'document',
            'searchable_text' => 'Report body that outlives the file.',
        ]];
        $gone = $this->buildWith(
            $this->actionWithFiles($goneRows, $dir),
            $this->defaultsOn(),
            $this->attachmentRows('deleted_report.pdf', 'Report body that outlives the file.')
        );
        $goneText = $this->call($this->actionWithFiles($goneRows, $dir), 'renderText', [$gone['meta'], $gone['records']]);
        $this->test('file gone from disk still exports its text', str_contains($goneText, 'Report body that outlives the file.'));
        $this->test('file gone from disk is still flagged as gone', ($gone['records'][1]['attachment_present'] ?? true) === false);
        $this->test('no path line for a file that is gone', !str_contains($goneText, 'attach manually'));

        $degradedRows = [[
            'original_name' => 'failed_scan.png',
            'physical_name' => 'phys_gone.png',
            'file_type' => 'image',
            'searchable_text' => 'failed_scan.png',
        ]];
        $degraded = $this->buildWith(
            $this->actionWithFiles($degradedRows, $dir),
            $this->defaultsOn(),
            $this->attachmentRows('failed_scan.png', 'failed_scan.png')
        );
        $degradedRecord = $degraded['records'][1];
        $this->test('filename-only image flagged degraded', $degradedRecord['degraded'] === true);
        $this->test('degraded image never exports the filename as content', !isset($degradedRecord['evidence']));
        $this->test('missing file detected', $degradedRecord['attachment_present'] === false);

        $degradedText = $this->call($this->actionWithFiles($degradedRows, $dir), 'renderText', [$degraded['meta'], $degraded['records']]);
        $this->test('degraded marker explains the failure', str_contains($degradedText, 'no text extracted from this image — transcription failed'));
        $this->test('degraded marker says the content is missing', str_contains($degradedText, 'the content is NOT in this export'));
        $this->test('degraded text carries no local path', !str_contains($degradedText, 'attach manually') && !str_contains($degradedText, 'uploads/phys_gone.png'));

        $unknown = $this->build($this->defaultsOn(), $this->attachmentRows('never_indexed.pdf', 'Some document text'));
        $unknownText = $this->call($this->action(), 'renderText', [$unknown['meta'], $unknown['records']]);
        $this->test('unindexed attachment falls back to a plain label', str_contains($unknownText, '[file] never_indexed.pdf'));
        $this->test('no path line without a stored row', !str_contains($unknownText, 'attach manually'));

        // Same original name uploaded twice: the newest row's file is gone, the older
        // one is still on disk. The export must prefer the copy it can point at.
        $duplicateRows = [
            [
                'original_name' => 'cv.pdf', 'physical_name' => 'phys_deleted.pdf',
                'file_type' => 'document', 'searchable_text' => 'newer text',
            ],
            [
                'original_name' => 'cv.pdf', 'physical_name' => 'phys_kept.pdf',
                'file_type' => 'document', 'searchable_text' => 'older text',
            ],
        ];
        file_put_contents($dir . '/phys_kept.pdf', 'x');
        $duplicate = $this->buildWith(
            $this->actionWithFiles($duplicateRows, $dir),
            $this->defaultsOn(),
            $this->attachmentRows('cv.pdf', 'older text')
        );
        $duplicateRecord = $duplicate['records'][1];
        $this->test('duplicate name prefers the copy still on disk', $duplicateRecord['attachment_present'] === true);
        $this->testEq('duplicate name resolves to the surviving path', 'uploads/phys_kept.pdf', $duplicateRecord['attachment_path'] ?? null);
        @unlink($dir . '/phys_kept.pdf');

        @unlink($dir . '/phys_real.png');
        @rmdir($dir);
    }

    private function runTextRender(): void
    {
        echo "\n=== text render ===\n";

        $built = $this->build($this->defaultsOn());
        $text = $this->call($this->action(), 'renderText', [$built['meta'], $built['records']]);

        $this->test('header present', str_contains($text, 'Localsy conversation export'));
        $this->test('title, model and session date on one line', str_contains($text, 'Flight prices · qwen35 · 2026-09-28'));
        $this->test('record tally line present', str_contains($text, '6 records: 1 user · 1 assistant · 3 context · 1 summary'));
        $this->test('token estimate present', (bool) preg_match('/≈\d/', $text));
        $this->test('turn labels present', str_contains($text, "USER\n") && str_contains($text, "AI [qwen35]\n"));
        $this->test('user text verbatim', str_contains($text, 'What is the cheapest flight to Helsinki?'));
        $this->test('reasoning labelled inline', str_contains($text, '[reasoning] Check the fare rules'));
        $this->test('answer text preserved', str_contains($text, 'The cheapest is 39 EUR.Baggage is extra.'));
        $this->test('source line has id, title and url', str_contains($text, '[S1] Ryanair deals — https://ryanair.com/deals'));
        $this->test('context record labelled with tool', str_contains($text, 'CONTEXT [search_web]'));
        $this->test('query line present', str_contains($text, 'query: vienna helsinki flights'));
        $this->test('page text travels with the group', str_contains($text, '[S1] Vienna to Helsinki from 39 EUR.'));
        $docChars = number_format(strlen('Full extracted document text of the travel policy.'));
        $this->test('attachment labelled then dumped', str_contains($text, 'travel-policy.pdf (' . $docChars . ' chars)') && str_contains($text, 'Full extracted document text of the travel policy.'));
        $this->test('no withheld markers in the export', !str_contains($text, 'text withheld'));
        $this->test('summary warns the turns are gone', str_contains($text, 'originals are gone from the session'));
        $this->test('omitted line present', str_contains($text, 'Omitted: '));
        $this->test('included line names what travels', str_contains($text, 'Included: reasoning, citations, web search, files, personal data'));
        $this->test('omitted lists filtered records', str_contains($text, '1 internal record(s)'));
        $this->test('no raw thought tags leak', !str_contains($text, '<|channel|>thought'));

        // Token sparsity: no rule banners, no per-record clocks, no per-record indexes.
        $this->test('no separator rules', !str_contains($text, '----'));
        $this->test('no clock timestamps in text', !preg_match('/\d{2}:\d{2}:\d{2}/', $text));
        $this->test('no record index brackets', !preg_match('/^\[\d+\] /m', $text));
        $this->testEq('one blank line between records', 6, substr_count($text, "\n\n"));
        $this->test('no triple newlines', substr_count($text, "\n\n\n") === 0);
        $this->test('text export stays lean', strlen($text) < 1100);

        $full = $this->build($this->with(['metrics' => '1']));
        $fullText = $this->call($this->action(), 'renderText', [$full['meta'], $full['records']]);
        $this->test('metrics line rendered', str_contains($fullText, '[metrics] 21.0s · TTFT 1.2s · 2 calls (first pass → answer)'));
        $this->test('metrics cache percent rendered', str_contains($fullText, '70% cached'));

        $minimal = $this->build([
            'thoughts' => '0', 'citations' => '0', 'metrics' => '0',
            'web' => '0', 'files' => '0', 'personal' => '0',
        ]);
        $minimalText = $this->call($this->action(), 'renderText', [$minimal['meta'], $minimal['records']]);
        $this->test('minimal export is turns only', !str_contains($minimalText, 'CONTEXT [') && !str_contains($minimalText, '[reasoning]') && !str_contains($minimalText, '[S1]'));
        $this->test('minimal export still carries both turns', str_contains($minimalText, 'What is the cheapest flight to Helsinki?') && str_contains($minimalText, 'The cheapest is 39 EUR.'));
        $this->test('minimal export stays minimal', strlen($minimalText) < 600);
    }

    private function runJsonRender(): void
    {
        echo "\n=== json render ===\n";

        $built = $this->build($this->with(['metrics' => '1']));
        $json = $this->call($this->action(), 'renderJson', [$built['meta'], $built['records']]);
        $decoded = json_decode($json, true);

        $this->test('json decodes', json_last_error() === JSON_ERROR_NONE);
        $this->testEq('format tag', 'localsy-conversation', $decoded['format'] ?? null);
        $this->testEq('record count', 6, count($decoded['records'] ?? []));
        $this->testEq('first record is the user turn', 'user', $decoded['records'][0]['role']);
        $this->testEq('summary marked by kind', 'summary', $decoded['records'][4]['kind']);
        $this->testEq('reasoning field carries both blocks', "Check the fare rules\n\nConfirm baggage", $decoded['records'][3]['reasoning']);
        $this->testEq('markdown and unicode survive verbatim', 'Vienna to Helsinki from 39 EUR.', $decoded['records'][1]['evidence'][0]['text']);
        $this->testEq('metrics nested in json', 70, $decoded['records'][3]['metrics']['cache_percent']);

        // Every context group off: no context records at all, and the manifest says which.
        $groupsOff = $this->build(['web' => '0', 'files' => '0', 'personal' => '0']);
        $this->testEq('no context records when every group is off', 0, $groupsOff['counts']['context']);
        $this->testEq('three groups counted as filtered', 3, $groupsOff['omitted']['context']);
        $this->testEq('excluded names the three groups', ['web search', 'files', 'personal data'], array_values(array_intersect($groupsOff['meta']['excluded'], ['web search', 'files', 'personal data'])));

        $offJson = $this->call($this->action(), 'renderJson', [$groupsOff['meta'], $groupsOff['records']]);
        $offDecoded = json_decode($offJson, true);
        $this->testEq('omitted list in json', true, in_array('metrics', $offDecoded['excluded'], true));
        $this->testEq('record count drops the context rows', 3, count($offDecoded['records']));
        $this->testEq('included list names the groups', true, in_array('web search, files, personal data', $decoded['included'], true));
        $this->test('included list has no payload entries', !in_array('web_page_text', $decoded['included'], true) && !in_array('file_image_text', $decoded['included'], true));
        $this->testEq('included list is plain names', ['reasoning', 'citations', 'web search, files, personal data', 'metrics'], $decoded['included']);
    }
}
