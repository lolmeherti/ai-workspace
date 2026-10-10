<?php

declare(strict_types=1);

namespace App\Services;

use App\AgentManager;
use App\Logger;

/**
 * Watching a video, from the turn's point of view.
 *
 * A pasted video link is not a page: the launcher runs the watch (captions, audio, transcription, media
 * deletion) and this turns what came back into a document the turn can use as evidence — the transcript,
 * fused from the video's own captions and the on-device transcription so the wording is reconciled
 * rather than one rendering being trusted over the other.
 *
 * The status text is the job's own, so the spinner says what is actually happening: fetching a model the
 * first time, downloading the audio, transcribing three of five.
 */
final class VideoWatchIngest
{
    public function __construct(
        private AgentManager $agent,
        private ?VideoJobGateway $gateway = null
    ) {}

    /**
     * @param string[] $urls
     * @param callable(string,array):void $emit status text for the stream
     * @param callable():bool $stillConnected false once the browser has gone
     * @return array{docs:array<int,array<string,string>>,failed:array<int,array<string,string>>,watched:int}
     */
    public function ingest(array $urls, string $query, callable $emit, callable $stillConnected): array
    {
        $runner = new VideoWatchRunner($this->gateway ?? new VideoWatchClient());
        $docs = [];
        $failed = [];

        foreach ($urls as $url) {
            $emit('status', ['text' => 'Let me watch that video for you…']);

            // The trace panel fills from here too, not only the spinner: a watch runs for minutes, the turn's
            // own calls have not started, and a panel that stays empty until they do says nothing about what
            // is being done. One line per stage, so the count moving inside a stage does not repeat it.
            $announced = '';
            $outcome = $runner->run($url, function (string $text, array $job = []) use ($emit, &$announced): void {
                $emit('status', ['text' => $text]);
                $line = self::stageTrace((string) ($job['stage'] ?? ''));
                if ($line !== null && $line['label'] !== $announced) {
                    $announced = $line['label'];
                    $emit('trace', $line);
                }
            }, $stillConnected);

            $job = is_array($outcome['job'] ?? null) ? $outcome['job'] : [];
            if (($outcome['status'] ?? '') !== 'done') {
                $failed[] = [
                    'url'    => $url,
                    'status' => (string) ($outcome['status'] ?? 'failed'),
                    'error'  => (string) ($outcome['error'] ?? ''),
                ];
                Logger::logEvent('video_watch_failed', 'The video could not be watched', [
                    'url'    => $url,
                    'status' => (string) ($outcome['status'] ?? 'failed'),
                    'error'  => (string) ($outcome['error'] ?? ''),
                ], 'warn', 'VideoWatchIngest');
                continue;
            }

            $result = is_array($job['result'] ?? null) ? $job['result'] : [];
            $doc = $this->document($url, $result, $emit);
            if ($doc === null) {
                // The launcher's own sentence is the only honest thing this turn has: a watch that produced
                // nothing has a reason, and dropping it leaves the user with an answer from nowhere.
                $notice = trim((string) ($result['notice'] ?? ''));
                if ($notice === '') {
                    $notice = 'I could not read this video just now.';
                }
                $failed[] = [
                    'url'    => $url,
                    'status' => 'empty',
                    'error'  => 'the watch produced no transcript',
                    'notice' => $notice,
                    'title'  => (string) (($result['metadata']['title'] ?? '')),
                ];
                $emit('trace', ['label' => $notice, 'color' => 'amber']);
                Logger::logEvent('video_watch_empty', 'The watch produced nothing to read', [
                    'url' => $url,
                    'outcome' => (string) ($result['outcome'] ?? ''),
                    'notice' => $notice,
                ], 'warn', 'VideoWatchIngest');
                continue;
            }

            $docs[] = $doc;
        }

        return ['docs' => $docs, 'failed' => $failed, 'watched' => count($docs)];
    }

    /**
     * The watch's result as a document. The two renderings of the same speech — the video's captions and
     * the on-device transcription — are reconciled by the fusion pass, which is the whole reason both are
     * obtained; when only one exists it is used verbatim, and that is recorded rather than hidden.
     *
     * @param array<string,mixed> $result
     * @return array<string,string>|null
     */
    private function document(string $url, array $result, callable $emit): ?array
    {
        $meta = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $duration = (float) ($meta['duration'] ?? 0.0);
        $captionKind = (string) ($result['caption_kind'] ?? '');

        $sources = [];
        // The track the watch chose is the captions; the other two are what it heard.
        $chosen = $captionKind === 'manual' ? ($result['manual'] ?? []) : ($result['auto'] ?? []);
        if (is_array($chosen) && $chosen !== []) {
            $sources[$captionKind === 'manual' ? 'manual' : 'auto'] = $chosen;
        }
        if (is_array($result['asr'] ?? null) && $result['asr'] !== []) {
            $sources['asr'] = $result['asr'];
        }
        if ($sources === []) {
            return null;
        }

        // The fusion pass is minutes of silence otherwise: it is where the model calls are, and the turn does
        // not begin until it is done.
        $emit('status', ['text' => 'Fusing the captions with the transcription…']);
        $emit('trace', ['label' => 'Fusing the captions with the transcription', 'color' => 'violet']);

        $fusion = new TranscriptFusion();
        $fused = $fusion->fuse($sources, ['title' => (string) ($meta['title'] ?? '')], $duration, $this->modelCall(), true);
        foreach ((array) ($fused['events'] ?? []) as $event) {
            Logger::logEvent((string) ($event['event'] ?? 'video_watch_fuse'), 'Transcription fusion', [
                'url' => $url,
            ] + (array) ($event['data'] ?? []), 'info', 'VideoWatchIngest');
        }
        $emit('trace', self::fusionTrace((array) $fused));

        $text = trim((string) ($fused['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $title = trim((string) ($meta['title'] ?? ''));
        $header = $title !== '' ? "Video: {$title}" : 'Video';
        if ($duration > 0) {
            $header .= ' (' . $fusion->clock($duration) . ')';
        }

        Logger::logEvent('video_watch_ingested', 'A watched video became evidence', [
            'url'         => $url,
            'title'       => $title,
            'language'    => (string) ($meta['language'] ?? ''),
            'captions'    => count((array) $chosen),
            'transcribed' => count((array) ($result['asr'] ?? [])),
            'chars'       => strlen($text),
        ], 'info', 'VideoWatchIngest');

        return [
            'url'         => $url,
            'domain'      => $host,
            'title'       => $title !== '' ? $title : $host,
            'sectionType' => 'transcript',
            'text'        => $header . "\n\n" . $text,
        ];
    }

    /** The trace line a job stage earns, once per stage. Stages the UI does not name get no line. */
    private static function stageTrace(string $stage): ?array
    {
        $lines = [
            'sources'    => ['label' => 'Reading the video and its captions', 'color' => 'cyan'],
            'audio'      => ['label' => 'Downloading the audio', 'color' => 'blue'],
            'transcribe' => ['label' => 'Transcribing the video on this machine', 'color' => 'indigo'],
        ];

        return $lines[$stage] ?? null;
    }

    /**
     * What the fusion pass did, in one line — the same events the log records, said plainly.
     *
     * @param array<string,mixed> $fused
     * @return array{label: string, color: string}
     */
    private static function fusionTrace(array $fused): array
    {
        $chunks = count((array) ($fused['chunks'] ?? []));

        $failed = 0;
        foreach ((array) ($fused['events'] ?? []) as $event) {
            $name = (string) ($event['event'] ?? '');
            if ($name === 'video_watch_fuse_skipped') {
                return ($event['data']['reason'] ?? '') === 'engine_unavailable'
                    ? ['label' => 'Fusion skipped — the engine was not answering', 'color' => 'amber']
                    : ['label' => 'Nothing to fuse — this video has one witness', 'color' => 'slate'];
            }
            if ($name === 'video_watch_fuse_failed' || $name === 'video_watch_fuse_tripwire') {
                $failed++;
            }
        }

        if ($failed > 0) {
            return [
                'label' => sprintf('Fusion fell back to the caption track for %d of %d chunks', $failed, $chunks),
                'color' => 'amber',
            ];
        }

        return [
            'label' => sprintf('Fused %d %s from the captions and the transcription', $chunks, $chunks === 1 ? 'chunk' : 'chunks'),
            'color' => 'emerald',
        ];
    }

    /** The fusion pass talks to the same engine everything else does, without streaming. */
    private function modelCall(): callable
    {
        return function (string $message): string {
            // Referring to the same speech twice and choosing the wording the sources support is not a task that
            // needs deliberation, and on this engine it is a task that fails when it gets any: measured, every
            // fusion call spent 3,000–6,000 tokens thinking before writing a word, so the replies arrived cut off
            // at the budget (8,192 exactly, JSON truncated, "unparseable") or as thinking with no answer at all.
            // The pass therefore runs in the runtime's own no-thinking mode, with the budget left to the answer.
            return $this->agent->chat(
                [['role' => 'user', 'content' => $message]],
                false,
                null,
                null,
                'video_fusion',
                'instruct',
                null,
                TranscriptFusion::ANSWER_TOKENS
            );
        };
    }
}
