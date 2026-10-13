<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\TranscriptFusion;

/**
 * Stage C of the video watcher: the deterministic half of evidence-fusion transcription.
 *
 * No model, no database — a model answer is just a string, so every rule the plan promises is checked here rather
 * than hoped for in a prompt: the comparison is computed before anything is asked, an answer is applied by the
 * number it carries, a line that is not built from its stretch's own words is thrown away, a number that does not
 * come back keeps the captions, and the transcript covers the video whatever the model does.
 */
class TranscriptFusionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): bool
    {
        $this->runIds();
        $this->runNormalisation();
        $this->runDisagreements();
        $this->runBatches();
        $this->runMaterial();
        $this->runCorrections();
        $this->runAssembly();
        $this->runFuse();
        $this->runRendering();

        echo "\n  {$this->passed} passed, {$this->failed} failed\n";
        foreach ($this->failures as $failure) {
            echo "    - {$failure}\n";
        }

        return $this->failed === 0;
    }

    // ── ids ─────────────────────────────────────────────────────────────
    private function runIds(): void
    {
        echo "\n=== stable ids ===\n";

        $fusion = new TranscriptFusion();
        $items = $fusion->assignIds([
            'manual' => [['start' => 10.0, 'end' => 12.0, 'text' => 'Qwen 3.8']],
            'auto'   => [['start' => 10.0, 'end' => 12.5, 'text' => 'queen 3.8']],
            'asr'    => [
                ['start' => 10.2, 'end' => 12.1, 'text' => 'Queen 3.8'],
                ['start' => 30.0, 'end' => 32.0, 'text' => 'flash next'],
            ],
        ]);

        $this->testEq('one item per input segment', 4, count($items));
        $this->testEq('manual cues are SM', 'SM-1', $items[0]['id']);
        $this->testEq('auto cues are SA', 'SA-1', $items[1]['id']);
        $this->testEq('asr segments are AS and keep their order', ['AS-1', 'AS-2'], [$items[2]['id'], $items[3]['id']]);
        $this->testEq('the list is in time order', [10.0, 10.0, 10.2, 30.0], array_column($items, 'start'));
        $this->testEq('a tie is broken towards the more trusted source', ['SM-1', 'SA-1', 'AS-1'],
            [$items[0]['id'], $items[1]['id'], $items[2]['id']]);
        $this->testEq('a source that is absent is simply not there', 1,
            count($fusion->assignIds(['asr' => [['start' => 1.0, 'end' => 2.0, 'text' => 'a']]])));
        $this->testEq('nothing in, nothing out', [], $fusion->assignIds([]));
    }

    // ── normalisation and the deterministic diff ────────────────────────
    private function runNormalisation(): void
    {
        echo "\n=== normalisation: lower case, letters and digits, nothing guessed ===\n";


        // Nothing is guessed: a filler, a spelled-out number or an accent counts as a difference and goes to the
        // model with everything else, rather than the backend deciding the two renderings agree.
        $this->test('a filler the transcription dropped is a difference',
            TranscriptFusion::normalize('uh, so this is it') !== TranscriptFusion::normalize('so this is it'));
        $this->test('a substituted word is a difference', TranscriptFusion::normalize('a plastic bottle')
            !== TranscriptFusion::normalize('a plastic battle'));
    }

    private function runDisagreements(): void
    {
        echo "\n=== where the two witnesses disagree ===\n";

        $fusion = new TranscriptFusion();

        // Three cues inside one transcribed stretch, saying the same words: nothing to ask about.
        $clean = $fusion->disagreements($fusion->assignIds([
            'auto' => [
                ['start' => 10.0, 'end' => 13.0, 'text' => 'hello wonderful person'],
                ['start' => 13.0, 'end' => 16.0, 'text' => 'this is Anton'],
                ['start' => 16.0, 'end' => 19.0, 'text' => 'and today we look at plastic'],
            ],
            'asr' => [['start' => 10.0, 'end' => 19.0, 'text' => 'Hello, wonderful person. This is Anton, and today we look at plastic.']],
        ]));
        $this->testEq('a stretch the captions corroborate is compared', 1, $clean['compared']);

        // The same stretch, heard differently: one stretch, carrying every cue it was read from.
        $dirty = $fusion->disagreements($fusion->assignIds([
            'auto' => [
                ['start' => 10.0, 'end' => 13.0, 'text' => 'hello wonderful person'],
                ['start' => 13.0, 'end' => 16.0, 'text' => 'this is Anton'],
                ['start' => 16.0, 'end' => 19.0, 'text' => 'and today we look at plastic'],
            ],
            'asr' => [['start' => 10.0, 'end' => 19.0, 'text' => 'hello wonderful person this is Anton and today we look at bats']],
        ]));
        $this->testEq('a stretch they differ on is one stretch', 1, count($dirty['spans']));

        // A stretch no cue covers has no second witness: not compared, so nothing to ask about.
        $uncovered = $fusion->disagreements($fusion->assignIds([
            'auto' => [['start' => 10.0, 'end' => 13.0, 'text' => 'hello']],
            'asr'  => [['start' => 60.0, 'end' => 70.0, 'text' => 'something nobody captioned']],
        ]));
        $this->testEq('a stretch no cue covers is not compared', 0, $uncovered['compared']);

        // One witness alone is not a disagreement either.
        $this->testEq('one witness is nothing to compare', ['spans' => [], 'compared' => 0],
            $fusion->disagreements($fusion->assignIds(['auto' => [['start' => 1.0, 'end' => 2.0, 'text' => 'alone']]])));

        // An automatic track is a scroll: each cue repeats the tail of the one before it, so a stretch read
        // together must not say anything twice. Uncollapsed, every stretch reads as a disagreement — measured on a
        // real pair, all 89 of them.
        $rolled = $fusion->disagreements($fusion->assignIds([
            'auto' => [
                ['start' => 10.0, 'end' => 13.0, 'text' => 'hello wonderful person. This is Anton'],
                ['start' => 13.0, 'end' => 16.0, 'text' => 'This is Anton and let us start this video'],
                ['start' => 16.0, 'end' => 19.0, 'text' => 'and let us start this video with a picture'],
            ],
            'asr' => [['start' => 10.0, 'end' => 19.0, 'text' => 'hello wonderful person. This is Anton and let us start this video with a picture']],
        ]));
    }

    // ── batching the stretches into calls ───────────────────────────────
    private function runBatches(): void
    {
        echo "\n=== the calls one video needs ===\n";

        $fusion = new TranscriptFusion();
        $spans = [];
        for ($i = 0; $i < 120; $i++) {
            $spans[] = ['at' => $i * 20.0, 'caption' => str_repeat('caption words ', 30), 'captionIds' => ['SA-' . $i],
                        'asr' => str_repeat('heard words ', 30), 'asrIds' => ['AS-' . $i]];
        }

        $batches = $fusion->spanBatches($spans);
        $this->test('a long video is more than one call', count($batches) > 1);
        $last = $batches[count($batches) - 1];

        $small = $fusion->spanBatches([['at' => 1.0, 'caption' => 'a', 'captionIds' => ['SA-1'], 'asr' => 'b', 'asrIds' => ['AS-1']]]);
        $this->testEq('one short stretch is one call', 1, count($small));
    }

    // ── the material one call gets ──────────────────────────────────────
    private function runMaterial(): void
    {
        echo "\n=== the material: numbered, both renderings, nothing else ===\n";

        $fusion = new TranscriptFusion();
        $material = $fusion->buildSpanMaterial([
            ['at' => 10.0, 'caption' => 'and today we look at plastic', 'captionIds' => ['SA-1'], 'asr' => 'and today we look at bats', 'asrIds' => ['AS-1']],
            ['at' => 30.0, 'caption' => 'the second thing', 'captionIds' => ['SA-2'], 'asr' => 'the second thing said', 'asrIds' => ['AS-2']],
        ], ['title' => 'A talk about plastic', 'language' => 'en']);

    }

    // ── what comes back ─────────────────────────────────────────────────
    private function runCorrections(): void
    {
        echo "\n=== the answer, applied by the number it carries ===\n";

        $fusion = new TranscriptFusion();
        $spans = [
            ['at' => 10.0, 'caption' => 'and today we look at plastic', 'captionIds' => ['SA-1'], 'asr' => 'and today we look at bats', 'asrIds' => ['AS-1']],
            ['at' => 30.0, 'caption' => 'the second thing', 'captionIds' => ['SA-2'], 'asr' => 'the second thing said', 'asrIds' => ['AS-2']],
        ];

        $answered = $fusion->readCorrections("1. and today we look at plastic\n2. the second thing said\n", $spans);
        $this->testEq('a numbered list is read by number', 2, count($answered['answers']));

        $partial = $fusion->readCorrections("1. and today we look at plastic\n", $spans);
        $this->testEq('a number that does not come back is a gap', [1], $partial['gaps']);

        $invented = $fusion->readCorrections("1. and today we look at a plastic bottle\n", $spans);
        $this->testEq('a line with a word neither rendering has is thrown away', [0], $invented['unusable']);

        $summarised = $fusion->readCorrections("1. plastic\n", $spans);

        $padded = $fusion->readCorrections("1. and today we look at plastic and today we look at plastic and today we look at plastic\n", $spans);

        $outOfRange = $fusion->readCorrections("1. and today we look at plastic\n9. the second thing\n", $spans);
        $this->testEq('a number no stretch has is dropped', [9], $outOfRange['bad']);

        $prose = $fusion->readCorrections("The speaker says the words above are mostly right and the second one too.", $spans);
        $this->testEq('an answer with no numbers at all is unparseable', 'unparseable', $prose['reason']);

        $nothing = $fusion->readCorrections('', $spans);
    }

    // ── the transcript, assembled here ──────────────────────────────────
    private function runAssembly(): void
    {
        echo "\n=== the transcript the sources and the answer make ===\n";

        $fusion = new TranscriptFusion();
        $sources = [
            'auto' => [
                ['start' => 10.0, 'end' => 13.0, 'text' => 'hello wonderful person'],
                ['start' => 13.0, 'end' => 16.0, 'text' => 'this is Anton'],
                ['start' => 30.0, 'end' => 33.0, 'text' => 'the captions agree here'],
            ],
            'asr' => [
                ['start' => 10.0, 'end' => 16.0, 'text' => 'hello person this is Anton'],
                ['start' => 60.0, 'end' => 70.0, 'text' => 'nobody captioned this'],
            ],
        ];
        $items = $fusion->assignIds($sources);
        $diff = $fusion->disagreements($items);
        $this->testEq('one stretch disagrees, one is uncovered', 1, count($diff['spans']));

        // The model kept the transcription's wording for the stretch it was shown.
        $kept = $fusion->assemble($items, $diff['spans'], [0 => 'hello person this is Anton']);
        $this->testEq('the transcript has three segments', 3, count($kept));
        $this->testEq('the answered stretch carries the transcription wording', 'hello person this is Anton', $kept[0]['text']);
        $this->testEq('the cue the stretch did not cover is carried by itself', 'the captions agree here', $kept[1]['text']);
        $this->testEq('the stretch nobody captioned is the transcription alone', 'nobody captioned this', $kept[2]['text']);

        // Nothing was accepted for the stretch: the captions stand, and the segment says so.
        $unreconciled = $fusion->assemble($items, $diff['spans'], []);
        $this->testEq('a stretch with no accepted wording keeps the captions', 'hello wonderful person this is Anton', $unreconciled[0]['text']);

        // A wording that is neither rendering, but built from both, is a merge.
        $merged = $fusion->assemble($items, $diff['spans'], [0 => 'hello wonderful person this is Anton']);
        $this->testEq('a wording equal to the captions is tagged with them', 'auto', $merged[0]['source']);
    }

    // ── the whole video ─────────────────────────────────────────────────
    private function runFuse(): void
    {
        echo "\n=== fusing a video ===\n";

        $sources = [
            'auto' => [
                ['start' => 10.0, 'end' => 13.0, 'text' => 'hello wonderful person'],
                ['start' => 13.0, 'end' => 16.0, 'text' => 'this is Anton'],
            ],
            'asr' => [
                ['start' => 10.0, 'end' => 16.0, 'text' => 'hello person this is Anton'],
                ['start' => 60.0, 'end' => 70.0, 'text' => 'nobody captioned this'],
            ],
        ];

        $called = 0;
        $model = function (string $message) use (&$called): string {
            $called++;

            return "1. hello person this is Anton\n";
        };

        $fused = (new TranscriptFusion())->fuse($sources, ['title' => 'A talk'], 70.0, $model);

        // A call that answers nothing leaves the captions standing and says so.
        $silent = (new TranscriptFusion())->fuse($sources, [], 70.0, static fn(string $m): string => '');
        $this->testEq('a call that answered nothing is reported', 'video_watch_fuse_failed', $silent['events'][0]['event'] ?? '');

        // One witness is nothing to fuse.
        $single = (new TranscriptFusion())->fuse(['asr' => [['start' => 5.0, 'end' => 6.0, 'text' => 'only witness']]], [], 10.0, static fn(string $m): string => '1. x');
        $this->testEq('a single witness is skipped, not called', 'video_watch_fuse_skipped', $single['events'][0]['event'] ?? '');

        // A cold engine costs wording, never the watch.
        $cold = (new TranscriptFusion())->fuse($sources, [], 70.0, static fn(string $m): string => '1. x', false);
        $this->testEq('a dead engine skips fusion rather than failing', 'engine_unavailable', $cold['events'][0]['data']['reason'] ?? '');
    }

    // ── rendering ───────────────────────────────────────────────────────
    private function runRendering(): void
    {
        echo "\n=== rendering ===\n";

        $fusion = new TranscriptFusion();

        $text = $fusion->renderText([
            ['t_start' => 10.0, 'source' => 'merged', 'text' => 'the words'],
            ['t_start' => 3661.0, 'source' => 'asr', 'text' => 'more words'],
        ]);
        $this->testEq('one line per segment, time and source first', "[00:10][merged] the words\n[1:01:01][asr] more words\n", $text);
        $this->testEq('nothing to render is nothing', '', $fusion->renderText([]));
    }

    private function test(string $label, bool $ok): void
    {
        if ($ok) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $this->failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }

    private function testEq(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) { $this->passed++; echo "  [PASS] {$label}\n"; return; }
        $this->failed++;
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $this->failures[] = "{$label}: expected {$e}, got {$a}";
        echo "  [FAIL] {$label} — expected {$e}, got {$a}\n";
    }
}
