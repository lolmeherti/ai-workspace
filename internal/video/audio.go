package video

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
)

// Toolchain is where the host binaries live. The launcher manages its own copies; a machine that
// happens to have them on PATH is enough for a probe.
type Toolchain struct {
	YtDlp   string
	FFmpeg  string
	FFprobe string
}

func (t Toolchain) resolve(name, configured string) string {
	if configured != "" {
		return configured
	}
	if found, err := exec.LookPath(name); err == nil {
		return found
	}

	return name
}

func (t Toolchain) ytDlp() string   { return t.resolve("yt-dlp", t.YtDlp) }
func (t Toolchain) ffmpeg() string  { return t.resolve("ffmpeg", t.FFmpeg) }
func (t Toolchain) ffprobe() string { return t.resolve("ffprobe", t.FFprobe) }

// ASRModels is the two-model set on disk: one for English, one for the other European languages, plus
// the speech detector that says which parts of the audio are worth sending at all.
type ASRModels struct {
	Base   string // the folder holding the runner and both models
	Runner string // the transcriber binary; empty means the managed copy under Base
	Vad    string // path to the silero VAD model; empty means the managed copy under Base
}

type ModelSet struct {
	Name    string
	Dir     string
	Encoder string
	Decoder string
	Joiner  string
	Tokens  string
}

// DefaultASRModels is where the launcher keeps the transcription side of things.
func DefaultASRModels(baseDir string) ASRModels {
	return ASRModels{Base: filepath.Join(baseDir, "asr")}
}

func (m ASRModels) runnerPath() string {
	if m.Runner != "" {
		return m.Runner
	}

	name := "sherpa-onnx-offline"
	if runtime.GOOS == "windows" {
		name += ".exe"
	}

	return filepath.Join(m.Base, "sherpa-onnx", "bin", name)
}

// vadRunnerPath is the speech detector's binary, and vadModelPath its model. Both come with the
// transcription runner in the same download.
func (m ASRModels) vadRunnerPath() string {
	name := "sherpa-onnx-vad"
	if runtime.GOOS == "windows" {
		name += ".exe"
	}

	return filepath.Join(m.Base, "sherpa-onnx", "bin", name)
}

func (m ASRModels) vadModelPath() string {
	if m.Vad != "" {
		return m.Vad
	}

	return filepath.Join(m.Base, "silero_vad.onnx")
}

// VadInstalled says whether speech detection is available. It is deliberately separate from Installed:
// transcription without a detector falls back to the plain grid, while transcription without a model is
// simply not possible.
func (m ASRModels) VadInstalled() bool {
	for _, path := range []string{m.vadRunnerPath(), m.vadModelPath()} {
		if _, err := os.Stat(path); err != nil {
			return false
		}
	}

	return true
}

// modelsFor picks the model the language needs, and says plainly when there is none: an unsupported
// language is not a failure, it is a video that will be read from its captions alone. An unknown language
// is not unsupported — the multilingual model identifies the language itself, so that is the model it gets.
func (m ASRModels) modelsFor(language string) (ModelSet, bool) {
	switch {
	case language == "en":
		return m.modelIn("parakeet-en", "parakeet unified en 0.6b"), true
	case language == "" || multilingualLanguages[language]:
		return m.modelIn("parakeet-multi", "parakeet tdt 0.6b v3"), true
	default:
		return ModelSet{}, false
	}
}

func (m ASRModels) modelIn(dir, name string) ModelSet {
	root := filepath.Join(m.Base, dir)

	return ModelSet{
		Name:    name,
		Dir:     root,
		Encoder: filepath.Join(root, "encoder.int8.onnx"),
		Decoder: filepath.Join(root, "decoder.int8.onnx"),
		Joiner:  filepath.Join(root, "joiner.int8.onnx"),
		Tokens:  filepath.Join(root, "tokens.txt"),
	}
}

// Installed says whether this language can actually be transcribed here and now.
func (m ASRModels) Installed(language string) bool {
	set, ok := m.modelsFor(language)
	if !ok {
		return false
	}
	for _, path := range []string{set.Encoder, set.Decoder, set.Joiner, set.Tokens, m.runnerPath()} {
		if _, err := os.Stat(path); err != nil {
			return false
		}
	}

	return true
}

// FetchAudio pulls the audio and makes the one form the transcriber wants: 16 kHz mono. Both files live
// in the workspace, which deletes itself, so no audio survives the watch.
func FetchAudio(ctx context.Context, tools Toolchain, ws *Workspace, url string, progress func(string)) (string, error) {
	if progress != nil {
		progress("Downloading the audio…")
	}

	staged, err := ws.File("audio.%(ext)s")
	if err != nil {
		return "", err
	}

	download := RunChild(ctx, tools.ytDlp(),
		"-x", "--audio-format", "opus", "--no-playlist", "--no-warnings", "--no-progress",
		"-o", staged, url)
	if download.Failed() {
		return "", fmt.Errorf("the audio could not be downloaded: %s", download.Reason())
	}

	source := ""
	matches, _ := filepath.Glob(strings.Replace(staged, "%(ext)s", "*", 1))
	for _, match := range matches {
		media, _ := ws.File(filepath.Base(match))
		if media != match {
			return "", errors.New("the audio landed outside the workspace")
		}
		if strings.HasSuffix(match, ".opus") || strings.HasSuffix(match, ".m4a") ||
			strings.HasSuffix(match, ".webm") || strings.HasSuffix(match, ".mp3") ||
			strings.HasSuffix(match, ".ogg") {
			source = match
			break
		}
	}
	if source == "" {
		return "", errors.New("the audio was not written")
	}

	if progress != nil {
		progress("Preparing the audio…")
	}

	wav, err := ws.File("audio.wav")
	if err != nil {
		return "", err
	}
	convert := RunChild(ctx, tools.ffmpeg(),
		"-hide_banner", "-loglevel", "error", "-y", "-i", source,
		"-ar", "16000", "-ac", "1", "-c:a", "pcm_s16le", wav)
	if convert.Failed() {
		return "", fmt.Errorf("the audio could not be prepared: %s", convert.Reason())
	}

	return wav, nil
}

// audioSeconds asks ffprobe how long the audio is, which is what the slice plan is built from.
func audioSeconds(ctx context.Context, tools Toolchain, wav string) (float64, error) {
	probe := RunChild(ctx, tools.ffprobe(),
		"-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", wav)
	if probe.Failed() {
		return 0, fmt.Errorf("could not read the audio's length: %s", probe.Reason())
	}

	value, err := strconv.ParseFloat(strings.TrimSpace(probe.Stdout), 64)
	if err != nil || value <= 0 {
		return 0, errors.New("the audio has no usable length")
	}

	return value, nil
}

// sliceLength is how much audio one slice carries.
//
// Measured on this machine (16 cores), same audio, 4 threads, the decoder started once for the whole
// batch:
//
//	 30s slices   20.2s for 300s of audio  -> 15x real time
//	 60s slices   23.0s                    -> 13x
//	150s slices   28.9s                    -> 10x
//	300s slices   35.1s                    ->  8.5x
//
// Shorter is faster per second of audio, because the encoder's attention cost grows faster than the
// audio does. An earlier measurement said the opposite — that was an artefact of starting a decoder
// process per slice, which paid a model load each time and made short slices look slow. The model also
// refuses long input outright: 600s, 540s, 480s and 420s all fail inside the encoder's attention,
// while 360s and below decode cleanly.
const sliceLength = 30.0

// batchSlices is how many slices one decoder run takes on. The decoder's answer is read through a
// bounded window and grows with the audio it covers, so a batch stays far below what that window
// holds: forty thirty-second slices is twenty minutes of audio per run, and a two-hour video is a
// handful of runs rather than one process per slice.
const batchSlices = 40

// sliceOverlap is carried so a word cut at a seam is decoded by both neighbours.
const sliceOverlap = 5.0

type audioSlice struct {
	start float64
	end   float64
}

// planSlices cuts the audio into slices no longer than sliceLength. Each slice after the first reaches
// back by the overlap, and that reach-back comes out of its own budget rather than adding to it — so a
// slice is never longer than the length the model was measured to accept.
func planSlices(total float64) []audioSlice {
	return planSlicesWithCap(total, sliceLength)
}

func planSlicesWithCap(total, cap float64) []audioSlice {
	if total <= 0 || cap <= 0 {
		return nil
	}
	if total <= cap {
		return []audioSlice{{0, total}}
	}

	var slices []audioSlice
	cursor := 0.0
	for cursor < total {
		from := cursor
		if len(slices) > 0 {
			from = cursor - sliceOverlap
			if from < 0 {
				from = 0
			}
		}
		end := from + cap
		if end > total {
			end = total
		}
		slices = append(slices, audioSlice{from, end})
		cursor = end
	}

	return slices
}

// threadCount keeps a transcription from taking the whole machine: the chat engine is on the GPU, but
// Apache, MySQL and PHP share the CPU with this.
func threadCount() int {
	threads := runtime.NumCPU() - 1
	if threads > 4 {
		threads = 4
	}
	if threads < 1 {
		threads = 1
	}

	return threads
}
