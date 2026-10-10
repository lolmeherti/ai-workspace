package video

import (
	"context"
	"errors"
	"fmt"
	"strconv"
)

// Transcribe runs the audio through the model the language needs, one chunk at a time, and returns the
// segments with their times put back on the video's timeline.
func Transcribe(
	ctx context.Context,
	tools Toolchain,
	models ASRModels,
	ws *Workspace,
	wav string,
	totalSeconds float64,
	language string,
	progress func(done, total int),
) ([]Segment, error) {
	return transcribeWithCap(ctx, tools, models, ws, wav, totalSeconds, language, sliceLength, progress)
}

// transcribeWithCap is the same run with the chunk-length cap supplied, so a measurement can sweep the
// cap over one audio file through this code rather than through a second implementation of the pipeline.
func transcribeWithCap(
	ctx context.Context,
	tools Toolchain,
	models ASRModels,
	ws *Workspace,
	wav string,
	totalSeconds float64,
	language string,
	cap float64,
	progress func(done, total int),
) ([]Segment, error) {
	set, ok := models.modelsFor(language)
	if !ok {
		return nil, fmt.Errorf("no transcription model handles %q", language)
	}
	if !models.Installed(language) {
		return nil, fmt.Errorf("the %s model is not installed", set.Name)
	}

	// Where the speech is decides what gets transcribed at all: Silero VAD is asked, and the chunks are
	// planned from its answer, so a stretch with nobody talking is never sent to the decoder.
	chunks, found := planAudioWithCap(ctx, tools, models, ws, wav, totalSeconds, cap)
	if !found {
		// The detector ran and heard no speech anywhere: this video has no local witness at all.
		return nil, nil
	}
	if len(chunks) == 0 {
		return nil, errors.New("the audio is too short to transcribe")
	}

	// The plan is the first honest count this watch can report: how many pieces the speech turned out to be
	// is only known once the detector has answered, and a total published before that is a guess the next
	// line contradicts.
	if progress != nil {
		progress(0, len(chunks))
	}

	return transcribeChunks(ctx, tools, models, ws, wav, set, chunks, progress)
}

// transcribeChunks cuts every chunk and then hands the batch to one decoder run: one process, one model
// load, many files. A decoder per chunk cost more than the transcription itself.
func transcribeChunks(
	ctx context.Context,
	tools Toolchain,
	models ASRModels,
	ws *Workspace,
	wav string,
	set ModelSet,
	chunks []chunk,
	progress func(done, total int),
) ([]Segment, error) {
	for index := range chunks {
		part, err := ws.File("chunk" + strconv.Itoa(chunks[index].Index) + ".wav")
		if err != nil {
			return nil, err
		}
		chunks[index].Path = part

		cut := RunChild(ctx, tools.ffmpeg(),
			"-hide_banner", "-loglevel", "error", "-y",
			"-ss", strconv.FormatFloat(chunks[index].Start, 'f', 3, 64),
			"-t", strconv.FormatFloat(chunks[index].End-chunks[index].Start, 'f', 3, 64),
			"-i", wav, "-ar", "16000", "-ac", "1", "-c:a", "pcm_s16le", part)
		if cut.Failed() {
			return nil, fmt.Errorf("a piece of the audio could not be cut: %s", cut.Reason())
		}
	}

	var tokens []string
	var times []float64
	covered := 0.0

	for first := 0; first < len(chunks); first += batchSlices {
		last := first + batchSlices
		if last > len(chunks) {
			last = len(chunks)
		}

		args := []string{
			"--encoder=" + set.Encoder,
			"--decoder=" + set.Decoder,
			"--joiner=" + set.Joiner,
			"--tokens=" + set.Tokens,
			"--model-type=nemo_transducer",
			"--provider=cpu",
			"--num-threads=" + strconv.Itoa(threadCount()),
		}
		for _, piece := range chunks[first:last] {
			args = append(args, piece.Path)
		}

		out := RunChild(ctx, models.runnerPath(), args...)
		if out.Failed() {
			return nil, fmt.Errorf("the transcription failed: %s", out.Reason())
		}
		if out.Truncated {
			return nil, errors.New("the transcriber's answer was too big to read")
		}

		heard, err := TranscriptsFromCLI(out.Stdout)
		if err != nil {
			return nil, err
		}
		if len(heard) != last-first {
			return nil, fmt.Errorf("the transcriber answered for %d of %d pieces of the audio", len(heard), last-first)
		}

		for index, transcript := range heard {
			// The piece's own start — its identity, carried from the plan — is what puts its words on
			// the video's timeline. Its place in this array is not an identifier.
			piece := chunks[first+index]

			// The seam closes token by token: whatever an earlier piece already decoded is dropped, the
			// rest is kept. At a pause there is nothing to drop, because no words are there at all.
			fresh, at := keepAfter(transcript.Tokens, transcript.Times, piece.Start, covered)
			if len(fresh) > 0 {
				tokens = append(tokens, fresh...)
				times = append(times, at...)
				covered = times[len(times)-1]
			}
		}

		// One decoder run takes on a batch, so the count moves a batch at a time: every piece of an answer
		// that arrived in one go would be a burst of numbers that reports nothing about the wait.
		if progress != nil {
			progress(last, len(chunks))
		}
	}

	if len(tokens) == 0 {
		return nil, nil
	}

	return GroupTokens(tokens, times), nil
}

// keepAfter returns the tokens that fall at or after the point the previous slice left off at, with
// their times shifted onto the video's timeline. A pause at a seam therefore costs nothing: those
// tokens are simply later than the point already covered.
func keepAfter(tokens []string, times []float64, offset, covered float64) ([]string, []float64) {
	count := len(tokens)
	if len(times) < count {
		count = len(times)
	}

	var keptTokens []string
	var keptTimes []float64
	for index := 0; index < count; index++ {
		at := times[index] + offset
		if at < covered {
			continue
		}
		keptTokens = append(keptTokens, tokens[index])
		keptTimes = append(keptTimes, at)
	}

	return keptTokens, keptTimes
}

// cueSegments turns parsed captions into the same shape the transcriber produces, so the two can be
// merged as two accounts of the same speech.
func cueSegments(cues []cue) []Segment {
	out := make([]Segment, 0, len(cues))
	for _, c := range cues {
		out = append(out, Segment{Start: c.start, End: c.end, Text: c.text})
	}

	return out
}
