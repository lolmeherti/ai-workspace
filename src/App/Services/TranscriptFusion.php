<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Evidence-fusion transcription — Stage C of `.hermes/plans/ui/youtube-watcher.md`.
 *
 * Two renderings of the same speech arrive: the video's own caption track and a local transcription. Where they
 * say the same words there is nothing to decide, so the comparison is made here, deterministically, after one
 * normalisation rule, and only the stretches that really differ are worth a model call.
 *
 * What the model is asked for is a numbered list: one line per number, the wording that stretch should read. The
 * numbers are ours, so a line is applied by the number it carries — a number that does not come back keeps the
 * captions for that stretch, a line not built from that stretch's own words is thrown away, and no timestamp is
 * ever typed by anyone: the times come from the caption cues the stretch was read from.
 *
 * Everything that is not a disagreement is assembled here from the sources, so the model cannot lose a word it
 * was never asked about, and the transcript covers the video whatever the model does.
 *
 * The only non-deterministic part is the model call, which is injected — everything else is pure.
 */
class TranscriptFusion
{
    /**
     * What one call may answer with, and the share of that budget the words themselves may take.
     *
     * The answer is a numbered list of the words the model was shown, so the material and the answer are the same
     * size: a call asking for more words than it can write comes back cut off. This is the cap the material is
     * batched against, and the same number is passed to the engine as the call's output budget.
     */
    public const ANSWER_TOKENS = 8192;
    public const ANSWER_SHARE = 0.5;

    /** A produced line is kept only if it is of a sane length against the longer rendering of its own stretch. */
    public const TRIPWIRE_LOW = 0.5;
    public const TRIPWIRE_HIGH = 1.25;

    /** Fallback trust order: manual sub beats auto sub beats ASR. */
    public const TRUST = ['manual' => 3, 'auto' => 2, 'asr' => 1];
    public const PREFIX = ['manual' => 'SM', 'auto' => 'SA', 'asr' => 'AS'];
    public const LABEL = ['manual' => 'sub:manual', 'auto' => 'sub:auto', 'asr' => 'asr'];

    public const INSTRUCTION = <<<'PROMPT'
Each number below is one stretch of the same speech, given twice: as the video's own captions rendered it, and as
a local transcription rendered it. The two differ somewhere in most stretches.

For each number, answer with the wording that stretch should read. Build it only from the words of that number's
own two renderings: never add a word that is in neither, never drop words that both agree on, never paraphrase.
Where the two disagree, keep the rendering the video's own words support.

Answer with a numbered list, one line per number, in order, and nothing else:

1. the wording stretch one should read
2. the wording stretch two should read

Never skip a number, never explain, no prose outside the list.
PROMPT;

    public function __construct(private int $usableContext = 24000)
    {
    }

    /** Sources hand in their own segment lists; ids are assigned here, in time order. */
    public function assignIds(array $sources): array
    {
        $items = [];
        foreach (self::PREFIX as $kind => $prefix) {
            $n = 0;
            foreach ($sources[$kind] ?? [] as $seg) {
                $n++;
                $items[] = [
                    'id'    => $prefix . '-' . $n,
                    'kind'  => $kind,
                    'start' => (float) ($seg['start'] ?? 0.0),
                    'end'   => (float) ($seg['end'] ?? 0.0),
                    'text'  => trim((string) ($seg['text'] ?? '')),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            return [$a['start'], -self::TRUST[$a['kind']], $a['id']]
                <=> [$b['start'], -self::TRUST[$b['kind']], $b['id']];
        });

        return $items;
    }

    /**
     * One rule, run on both renderings: everything becomes lower case, and everything that is not a letter or a
     * digit becomes a separator. Case, punctuation, hyphens, dashes and spacing therefore cannot make two
     * renderings look different; nothing else is guessed, because guessing is where a comparison starts lying.
     * A filler the transcription dropped, a number spelled out as a word and an accent are all differences here,
     * and they go to the model with everything else rather than being decided in the backend.
     */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text, 'UTF-8')));
    }

    /**
     * Where the two witnesses disagree, span by span.
     *
     * The unit is the transcription's own segment, because that is the granularity at which a second account of
     * the same speech exists: a caption track is cut into two-second cues while the transcriber answers in
     * ten-to-twenty-second stretches, so the cues inside a stretch are read together and compared against what
     * was heard. A stretch no cue covers has no second witness and is not a disagreement — there is nothing to
     * reconcile, and nothing to ask about.
     *
     * @param array<int, array{id: string, kind: string, start: float, end: float, text: string}> $items
     * @return array{spans: array<int, array{at: float, caption: string, captionIds: string[], asr: string, asrIds: string[]}>, compared: int}
     */
    public function disagreements(array $items): array
    {
        return $this->disagreementsWith($items, [self::class, 'normalize']);
    }

    /**
     * The same comparison under a different rule for what counts as the same words, so a rule can be measured
     * against a real pair of witnesses instead of argued about.
     */
    public function disagreementsWith(array $items, callable $normalize): array
    {
        $captions = [];
        $heard = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'asr') {
                $heard[] = $item;
            } else {
                $captions[] = $item;
            }
        }
        if ($captions === [] || $heard === []) {
            return ['spans' => [], 'compared' => 0];
        }

        $spans = [];
        $compared = 0;
        foreach ($heard as $segment) {
            $inside = [];
            foreach ($captions as $cue) {
                if ($cue['start'] < $segment['end'] && $cue['end'] > $segment['start']) {
                    $inside[] = $cue;
                }
            }
            if ($inside === []) {
                continue;
            }
            $compared++;
            $caption = self::joinCues($inside);
            if ($normalize($caption) === $normalize($segment['text'])) {
                continue;
            }
            $spans[] = [
                'at'         => $segment['start'],
                'caption'    => $caption,
                'captionIds' => array_column($inside, 'id'),
                'asr'        => $segment['text'],
                'asrIds'     => [$segment['id']],
            ];
        }

        return ['spans' => $spans, 'compared' => $compared];
    }

    /**
     * The captions of one stretch, read together as the sentence they make.
     *
     * An automatic caption track is a scroll, not a partition: each cue repeats the tail of the one before it as
     * the line moves up, so joining them plainly says everything twice ("and let's start this video with this and
     * let's start this video with this very bizarre picture"). Carried into the comparison that reads as a
     * disagreement with any clean account of the same speech — measured on a real pair, it made all 89 stretches
     * look different. Each cue is therefore joined at its longest overlap with what has been read so far.
     *
     * @param array<int, array{text: string}> $cues
     */
    private static function joinCues(array $cues): string
    {
        $text = '';
        foreach ($cues as $cue) {
            $text = self::appendAtOverlap($text, (string) $cue['text']);
        }

        return trim($text);
    }

    /** Append what a cue adds to what the previous ones already said, without saying it twice. */
    private static function appendAtOverlap(string $text, string $next): string
    {
        $next = trim($next);
        if ($text === '' || $next === '') {
            return trim($text . ' ' . $next);
        }

        $have = preg_split('/\s+/', $text) ?: [];
        $add = preg_split('/\s+/', $next) ?: [];
        $most = min(count($have), count($add));
        for ($overlap = $most; $overlap > 0; $overlap--) {
            if (array_slice($have, -$overlap) === array_slice($add, 0, $overlap)) {
                return trim($text . ' ' . implode(' ', array_slice($add, $overlap)));
            }
        }

        return trim($text . ' ' . $next);
    }

    /**
     * The stretches, cut into the calls that fit what one answer can hold. Each batch is a contiguous slice of the
     * disagreement list and is told where it starts, so an answer can be applied to the right stretch.
     *
     * @param array<int, array{at: float, caption: string, captionIds: string[], asr: string, asrIds: string[]}> $spans
     * @return array<int, array{offset: int, at: float, chars: int, spans: array<int, array<string, mixed>>}>
     */
    public function spanBatches(array $spans): array
    {
        $cap = (int) (self::ANSWER_TOKENS * self::ANSWER_SHARE * 4);
        $batches = [];
        $current = [];
        $chars = 0;
        $offset = 0;

        foreach ($spans as $span) {
            $size = strlen($span['caption']) + strlen($span['asr']) + 40;
            if ($current !== [] && $chars + $size > $cap) {
                $batches[] = ['offset' => $offset, 'at' => $current[0]['at'], 'chars' => $chars, 'spans' => $current];
                $offset += count($current);
                $current = [];
                $chars = 0;
            }
            $current[] = $span;
            $chars += $size;
        }
        if ($current !== []) {
            $batches[] = ['offset' => $offset, 'at' => $current[0]['at'], 'chars' => $chars, 'spans' => $current];
        }

        return $batches;
    }

    /** The material one call receives: what is known about the video, then only the stretches that differ. */
    public function buildSpanMaterial(array $spans, array $metadata = []): string
    {
        $lines = $this->metadataLines($metadata);
        foreach ($spans as $position => $span) {
            $lines[] = '';
            $lines[] = ($position + 1) . '.';
            $lines[] = 'captions:      ' . $span['caption'];
            $lines[] = 'transcription: ' . $span['asr'];
        }

        return implode("\n", $lines) . "\n\n" . self::INSTRUCTION;
    }

    /** The video's own details — the lexicon a wording is checked against, and the timeline it is placed in. */
    private function metadataLines(array $metadata): array
    {
        $lines = [];
        if (!empty($metadata['title'])) {
            $lines[] = '[video] title: ' . $metadata['title'];
        }
        $head = [];
        foreach (['channel', 'duration', 'language'] as $field) {
            if (!empty($metadata[$field])) {
                $head[] = $field . ': ' . $metadata[$field];
            }
        }
        if ($head !== []) {
            $lines[] = '[video] ' . implode('   ', $head);
        }
        if (!empty($metadata['tags'])) {
            $lines[] = '[meta] tags: ' . implode(', ', (array) $metadata['tags']);
        }
        if (!empty($metadata['description'])) {
            $lines[] = '[meta] description: ' . $metadata['description'];
        }
        foreach ($metadata['chapters'] ?? [] as $chapter) {
            $lines[] = sprintf('[chapter %s-%s] %s', $this->clock((float) ($chapter['start'] ?? 0.0)),
                $this->clock((float) ($chapter['end'] ?? 0.0)), (string) ($chapter['title'] ?? ''));
        }

        return $lines;
    }

    /**
     * What one answer said, line by line, checked against the stretch each number belongs to.
     *
     * A line is applied by the number it carries, never by its position: a number out of range is dropped, a line
     * that is not built from its stretch's own words is dropped, a number that never comes back is a gap — and a
     * gap keeps the captions, which is what a dropped line does too. Nothing here trusts the model's word about
     * what it was given; the stretch it named is the only one it can have meant.
     *
     * @param array<int, array{at: float, caption: string, captionIds: string[], asr: string, asrIds: string[]}> $spans
     * @return array{answers: array<int, string>, gaps: array<int, int>, unusable: array<int, int>, bad: array<int, int>, reason: string|null}
     */
    public function readCorrections(string $raw, array $spans): array
    {
        $answers = [];
        $unusable = [];
        $bad = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (!preg_match('/^\s*(\d+)\s*[.)\-:]\s*(.+?)\s*$/u', $line, $match)) {
                continue;
            }
            $number = (int) $match[1];
            $index = $number - 1;
            if (!isset($spans[$index]) || isset($answers[$index])) {
                $bad[] = $number;
                continue;
            }
            if (!$this->readable($match[2], $spans[$index])) {
                $unusable[] = $index;
                continue;
            }
            $answers[$index] = trim($match[2]);
        }

        if ($answers === [] && $unusable === []) {
            // Nothing to apply at all. An empty reply is not a parse failure: the call spent its whole budget and
            // answered nothing, which is its own finding.
            $reason = trim($raw) === '' ? 'no_answer' : 'unparseable';

            return ['answers' => [], 'gaps' => [], 'unusable' => [], 'bad' => [], 'reason' => $reason];
        }

        $gaps = [];
        foreach ($spans as $index => $span) {
            if (!isset($answers[$index]) && !in_array($index, $unusable, true)) {
                $gaps[] = $index;
            }
        }

        return ['answers' => $answers, 'gaps' => $gaps, 'unusable' => $unusable, 'bad' => $bad, 'reason' => null];
    }

    /**
     * Whether a produced line is built from its stretch's own words.
     *
     * Two deterministic checks: every word of the line must occur in the two renderings it was read from, counted,
     * so it cannot use a word twice that occurs once; and the line must be of a sane length against the longer
     * rendering, which is what catches a line that summarised the stretch. What fails is not applied — the stretch
     * keeps its captions, so a rejection costs wording quality and never content.
     */
    private function readable(string $line, array $span): bool
    {
        $produced = self::words($line);
        if ($produced === []) {
            return false;
        }

        $available = array_count_values(array_merge(self::words($span['caption']), self::words($span['asr'])));
        foreach (array_count_values($produced) as $word => $count) {
            if (($available[(string) $word] ?? 0) < $count) {
                return false;
            }
        }

        $longest = max(count(self::words($span['caption'])), count(self::words($span['asr'])));
        if ($longest > 0) {
            $count = count($produced);
            if ($count < self::TRIPWIRE_LOW * $longest || $count > self::TRIPWIRE_HIGH * $longest) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * Fuse one video. $callModel receives the user message and returns the model's raw reply.
     *
     * $engineUp is false when no inference endpoint is answering. A cold engine must not cost the user the watch:
     * acquisition and transcription have already run, and no part of them depends on which model — or which
     * engine — is loaded. The transcript then carries every source as it stands, each segment tagged with the
     * source it came from, the reason recorded, and it stays re-fusable later without repeating acquisition or ASR.
     *
     * A video with a single witness has nothing to fuse and takes the same path.
     */
    public function fuse(array $sources, array $metadata, float $duration, callable $callModel, bool $engineUp = true): array
    {
        $items = $this->assignIds($sources);
        $kinds = [];
        foreach ($items as $item) {
            $kinds[$item['kind']] = true;
        }

        if ($items === []) {
            return ['text' => '', 'segments' => [], 'chunks' => [], 'events' => [], 'provenance' => []];
        }

        if (!$engineUp) {
            return $this->unfused($items, $sources, [[
                'event' => 'video_watch_fuse_skipped',
                'data'  => ['reason' => 'engine_unavailable'],
            ]]);
        }

        if (count($kinds) < 2) {
            return $this->unfused($items, $sources, [[
                'event' => 'video_watch_fuse_skipped',
                'data'  => ['reason' => 'single_source', 'kind' => (string) array_key_first($kinds)],
            ]]);
        }

        $spans = $this->disagreements($items)['spans'];
        $answers = [];
        $events = [];
        $summaries = [];

        foreach ($this->spanBatches($spans) as $index => $batch) {
            $raw = (string) $callModel($this->buildSpanMaterial($batch['spans'], $metadata));
            $read = $this->readCorrections($raw, $batch['spans']);

            foreach ($read['answers'] as $local => $text) {
                $answers[$batch['offset'] + $local] = $text;
            }
            if ($read['reason'] !== null) {
                $events[] = ['event' => 'video_watch_fuse_failed', 'data' => [
                    'batch' => $index, 'reason' => $read['reason'], 'stretches' => count($batch['spans']),
                    'answer_chars' => strlen($raw),
                ]];
            }
            if ($read['unusable'] !== []) {
                $ids = [];
                foreach ($read['unusable'] as $local) {
                    $ids[] = $batch['spans'][$local]['captionIds'][0] ?? '';
                }
                $events[] = ['event' => 'video_watch_fuse_tripwire', 'data' => [
                    'batch' => $index, 'unusable' => count($read['unusable']), 'ids' => array_slice($ids, 0, 5),
                ]];
            }
            if ($read['gaps'] !== []) {
                $ids = [];
                foreach ($read['gaps'] as $local) {
                    $ids = array_merge($ids, $batch['spans'][$local]['captionIds']);
                }
                $events[] = ['event' => 'video_watch_fuse_gap', 'data' => [
                    'batch' => $index, 'stretches' => count($read['gaps']), 'ids' => array_slice($ids, 0, 5),
                ]];
            }
            if ($read['bad'] !== []) {
                $events[] = ['event' => 'video_watch_fuse_bad_id', 'data' => [
                    'batch' => $index, 'numbers' => array_slice($read['bad'], 0, 5),
                ]];
            }

            $summaries[] = [
                'stretches' => count($batch['spans']), 'answered' => count($read['answers']),
                'unusable' => count($read['unusable']), 'reason' => $read['reason'],
            ];
        }

        return $this->assembled($this->assemble($items, $spans, $answers), $summaries, $events, $sources);
    }

    /**
     * The transcript, built here from the sources and the accepted lines.
     *
     * Every cue and every transcribed stretch lands in exactly one output segment: a stretch the two witnesses
     * disagreed on carries its accepted wording, or its captions when nothing was accepted for it; every other cue
     * is carried by itself; and a stretch no cue covers is the transcription alone, because that is the only
     * account of it. Nothing is said twice and nothing is dropped, because the timeline is partitioned here rather
     * than reconstructed from what a model chose to mention.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array{at: float, caption: string, captionIds: string[], asr: string, asrIds: string[]}> $spans
     * @param array<int, string> $answers accepted wordings, by the stretch's index in $spans
     */
    public function assemble(array $items, array $spans, array $answers): array
    {
        $known = [];
        foreach ($items as $item) {
            $known[$item['id']] = $item;
        }

        $segments = [];
        $claimed = [];
        foreach ($spans as $index => $span) {
            $captionIds = array_values(array_filter($span['captionIds'], static fn(string $id): bool => isset($known[$id])));
            $asrIds = array_values(array_filter($span['asrIds'], static fn(string $id): bool => isset($known[$id])));
            if ($captionIds === [] && $asrIds === []) {
                continue;
            }
            foreach ($captionIds as $id) {
                $claimed[$id] = true;
            }

            $accepted = trim((string) ($answers[$index] ?? ''));
            $wording = $accepted !== '' ? $accepted : trim($span['caption']);
            if ($wording === '') {
                $wording = trim($span['asr']);   // the caption side carries nothing: the transcription is the account
            }
            if ($wording === '') {
                continue;
            }

            $kind = $known[$captionIds[0] ?? ($asrIds[0] ?? '')]['kind'] ?? 'auto';
            $source = $accepted === '' ? $kind : $this->wordingSource($wording, $span, $kind);
            [$start, $end] = $this->spanTimes($captionIds, $asrIds, $known);

            $segments[] = [
                'ids'        => $source === 'asr' ? array_merge($captionIds, $asrIds) : ($captionIds !== [] ? $captionIds : $asrIds),
                'source'     => $source,
                'text'       => $wording,
                'confidence' => $accepted === '' ? 'low' : 'high',
                't_start'    => $start,
                't_end'      => $end,
            ];
        }

        // Every cue the disagreeing stretches did not claim, exactly as it reads.
        foreach ($items as $item) {
            if ($item['kind'] === 'asr' || isset($claimed[$item['id']]) || $item['text'] === '') {
                continue;
            }
            $segments[] = [
                'ids'        => [$item['id']],
                'source'     => $item['kind'],
                'text'       => $item['text'],
                'confidence' => 'high',
                't_start'    => $item['start'],
                't_end'      => $item['end'],
            ];
        }

        // A stretch no cue covers: the transcription standing alone.
        foreach ($items as $item) {
            if ($item['kind'] !== 'asr' || $item['text'] === '' || $this->coveredByCue($item, $items)) {
                continue;
            }
            $segments[] = [
                'ids'        => [$item['id']],
                'source'     => 'asr',
                'text'       => $item['text'],
                'confidence' => 'high',
                't_start'    => $item['start'],
                't_end'      => $item['end'],
            ];
        }

        usort($segments, static fn(array $a, array $b): int => $a['t_start'] <=> $b['t_start']);

        return $segments;
    }

    /** Which rendering an accepted wording turned out to be, so the citation says where the words came from. */
    private function wordingSource(string $wording, array $span, string $captionKind): string
    {
        $produced = self::normalize($wording);
        if ($produced === self::normalize($span['caption'])) {
            return $captionKind;
        }
        if ($produced === self::normalize($span['asr'])) {
            return 'asr';
        }

        return 'merged';
    }

    /**
     * When a stretch happened. A cue's time is video-accurate; the transcription's segment times drift, so they
     * only answer where no cue is there to.
     *
     * @param array<int, string> $captionIds
     * @param array<int, string> $asrIds
     */
    private function spanTimes(array $captionIds, array $asrIds, array $known): array
    {
        $ids = $captionIds !== [] ? $captionIds : $asrIds;
        $start = null;
        $end = null;
        foreach ($ids as $id) {
            $start = $start === null ? $known[$id]['start'] : min($start, $known[$id]['start']);
            $end = $end === null ? $known[$id]['end'] : max($end, $known[$id]['end']);
        }

        return [$start ?? 0.0, $end ?? ($start ?? 0.0)];
    }

    /** Whether any caption cue covers this transcribed stretch. */
    private function coveredByCue(array $segment, array $items): bool
    {
        foreach ($items as $item) {
            if ($item['kind'] !== 'asr' && $item['start'] < $segment['end'] && $item['end'] > $segment['start']) {
                return true;
            }
        }

        return false;
    }

    /** One line per fused segment: "[12:31][merged] text". */
    public function renderText(array $segments): string
    {
        $lines = [];
        foreach ($segments as $seg) {
            $lines[] = sprintf('[%s][%s] %s', $this->clock((float) $seg['t_start']), (string) $seg['source'], (string) $seg['text']);
        }

        return implode("\n", $lines) === '' ? '' : implode("\n", $lines) . "\n";
    }

    public function clock(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    /** Every source as it stands, each segment tagged: what survives when there is nothing to fuse with. */
    private function unfused(array $items, array $sources, array $events): array
    {
        $segments = [];
        foreach ($items as $item) {
            if ($item['text'] === '') {
                continue;
            }
            $segments[] = [
                'ids'        => [$item['id']],
                'source'     => $item['kind'],
                'text'       => $item['text'],
                'confidence' => 'high',
                't_start'    => $item['start'],
                't_end'      => $item['end'],
            ];
        }

        return $this->assembled($segments, [], $events, $sources);
    }

    private function assembled(array $segments, array $summaries, array $events, array $sources): array
    {
        $provenance = ['manual' => 0, 'auto' => 0, 'asr' => 0, 'merged' => 0, 'low' => 0];
        foreach ($segments as $seg) {
            $provenance[$seg['source']] = ($provenance[$seg['source']] ?? 0) + 1;
            if (($seg['confidence'] ?? 'high') === 'low') {
                $provenance['low']++;
            }
        }

        return [
            'text'       => $this->renderText($segments),
            'segments'   => $segments,
            'chunks'     => $summaries,
            'events'     => $events,
            'provenance' => $provenance,
            'sources'    => $this->rawSources($sources),
        ];
    }

    /** The raw text sources behind a fused transcript, kept so a re-fuse needs no second download. */
    private function rawSources(array $sources): array
    {
        $out = [];
        foreach (self::PREFIX as $kind => $prefix) {
            $n = 0;
            foreach ($sources[$kind] ?? [] as $seg) {
                $n++;
                $out[$prefix . '-' . $n] = [
                    'kind'  => $kind,
                    'start' => (float) ($seg['start'] ?? 0.0),
                    'end'   => (float) ($seg['end'] ?? 0.0),
                    'text'  => trim((string) ($seg['text'] ?? '')),
                ];
            }
        }

        return $out;
    }
}
