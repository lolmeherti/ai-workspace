package video

import (
	"context"
	"fmt"

	"localsy/internal/util"
)

// WatchWorker runs the authorised pipeline: find the captions, take the audio, transcribe it with the
// model the language needs, and hand both accounts back on the job.
//
// What happens to them — merging the two, answering from the result — belongs to the web side. Nothing
// here stores anything, and the audio and captions live in the workspace, which deletes itself.
type WatchWorker struct {
	Tools       Toolchain
	Models      ASRModels
	MaxDuration float64
	// BinDir is where the downloader and ffmpeg are fetched to the first time a watch needs them. Empty
	// means the machine is expected to provide them on PATH.
	BinDir string
	// BrowserCaptions asks the page's own player for the chosen caption track — the only client YouTube
	// still serves the subtitle bytes to, and therefore the route that actually produces captions. Nil
	// leaves the downloader as the only caption route.
	BrowserCaptions BrowserCaptionSource
}

func (w *WatchWorker) Run(ctx context.Context, job *Job, ws *Workspace) (*Result, error) {
	sources := func(text string) { job.SetStage(StageSources, text) }
	audio := func(text string) { job.SetStage(StageAudio, text) }

	// The downloader comes first, because reading the video is itself a downloader call: the metadata
	// and the caption listing are both yt-dlp. When it is missing there is nothing to read and nothing
	// to fall back on, so this is a failure rather than a degraded watch — and it is the failure the
	// user is owed in one sentence, not an exit code from a binary that was never there.
	sources("Preparing the video downloader…")
	tools, err := EnsureDownloader(ctx, w.BinDir, w.Tools, sources)
	if err != nil {
		return nil, fmt.Errorf("the downloader that reads videos could not be prepared: %w", err)
	}

	sources("Reading the video…")
	report, err := InterrogateCaptionsWithBrowser(ctx, tools, ws, job.URL, w.MaxDuration, w.BrowserCaptions)
	if err != nil {
		return nil, err
	}

	if report.Source != "" {
		util.LogPrint("[+] watch %s: captions came from the %s (%d cues, kind %s)\n", job.ID, report.Source, report.Cues, report.ChosenKind)
	}

	result := &Result{
		Metadata: Metadata{
			Title:    report.Title,
			Language: report.Language,
			Duration: report.Duration,
		},
		Outcome:       report.Outcome,
		Notice:        report.Notice,
		Captions:      report.Captions,
		CaptionKind:   report.ChosenKind,
		CaptionSource: report.Source,
	}
	if report.ChosenKind == "manual" {
		result.Manual = report.Captions
	} else {
		result.Auto = report.Captions
	}

	if report.Outcome == "video_too_long" {
		// The stop for a video that is too long: nothing is downloaded, nothing is transcribed.
		return result, nil
	}

	if _, ok := w.Models.modelsFor(report.Language); !ok {
		// No model handles this language, so the captions are all there is. Say so rather than pretend.
		if len(report.Captions) > 0 {
			result.Notice = fmt.Sprintf("I could not transcribe this video — no transcription model handles %s — so I am working from the video's own captions.", languageName(report.Language))
		}

		return result, nil
	}

	// Nothing past this point is fetched before it is needed: the audio tools, the speech detector and
	// the model all arrive here, and a fetch that cannot happen degrades to the captions instead of
	// failing the watch.
	audio("Preparing the audio tools…")
	if tools, err = EnsureAudioTools(ctx, w.BinDir, tools, audio); err != nil {
		util.LogPrint("[-] watch %s: the audio tools could not be prepared: %v\n", job.ID, err)
		if len(report.Captions) > 0 {
			result.Notice = "I could not fetch the tools needed to transcribe this video, so I am working from its own captions."
		}

		return result, nil
	}
	if err := w.Models.Ensure(ctx, report.Language, audio); err != nil {
		util.LogPrint("[-] watch %s: the transcription model could not be prepared: %v\n", job.ID, err)
		if len(report.Captions) > 0 {
			result.Notice = "I could not fetch the transcription model for this video, so I am working from its own captions."
		}

		return result, nil
	}

	wav, err := FetchAudio(ctx, tools, ws, job.URL, audio)
	if err != nil {
		// The audio is the second witness, not the whole watch. A refused download degrades to the video's
		// own captions exactly the way a missing model or a missing tool does — otherwise a video whose
		// captions are sitting right there produces nothing at all. The raw reason goes to the log.
		util.LogPrint("[-] watch %s: the audio could not be fetched: %v\n", job.ID, err)
		if len(report.Captions) > 0 {
			result.Notice = "I could not download this video's audio, so I am working from its own captions."
			return result, nil
		}

		return nil, err
	}

	total, err := audioSeconds(ctx, tools, wav)
	if err != nil || total <= 0 {
		total = report.Duration
	}

	// Finding the speech comes before the count: how many pieces the speech transcribes into is the detector's
	// answer, so this stage says what it is doing rather than quoting a total it does not have yet.
	job.SetStage(StageTranscribe, "Finding the speech…")
	segments, err := Transcribe(ctx, tools, w.Models, ws, wav, total, report.Language, func(done, total int) {
		job.Progress(StageTranscribe, fmt.Sprintf("Transcribing %d of %d…", done, total), done, total)
	})
	if err != nil {
		return nil, err
	}

	result.ASR = segments
	result.Outcome = "transcribed"

	return result, nil
}
