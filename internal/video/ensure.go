package video

import (
	"archive/tar"
	"archive/zip"
	"compress/bzip2"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"localsy/internal/download"
)

// What a watch needs on disk, and where it comes from. Nothing here is fetched until a watch actually
// runs: no download at boot, none at install (locked decision 17).
//
// The runtime and the detector are pinned versions, because the pipeline was measured against them.
const (
	ytDlpURL   = "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe"
	ffmpegURL  = "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip"
	sherpaURL  = "https://github.com/k2-fsa/sherpa-onnx/releases/download/v1.13.8/sherpa-onnx-v1.13.8-win-x64-shared-MD-Release-no-tts.tar.bz2"
	vadURL     = "https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/silero_vad.onnx"
	parakeetEN = "https://huggingface.co/csukuangfj2/sherpa-onnx-nemo-parakeet-unified-en-0.6b-int8-non-streaming/resolve/main"
	parakeetML = "https://huggingface.co/csukuangfj/sherpa-onnx-nemo-parakeet-tdt-0.6b-v3-int8/resolve/main"
)

// asset is one file a watch needs: where it comes from, where it goes, and the size below which it is
// treated as a broken or half-finished download rather than a file to keep.
type asset struct {
	url  string
	path string
	min  int64
}

// EnsureDownloader returns a Toolchain whose downloader can actually run, fetching the launcher's own
// copy only when neither the caller nor the machine already provides one.
//
// It runs before the video is read, because reading the video *is* a downloader call: the metadata and
// the caption listing both come from yt-dlp. With this fetch sitting after that step, the first watch
// on a machine without yt-dlp died inside it — nothing had been fetched yet, so there was nothing to
// fall back on either, and the watch failed instead of the step that needed the tool.
func EnsureDownloader(ctx context.Context, binDir string, configured Toolchain, progress func(string)) (Toolchain, error) {
	tools := configured
	if binDir == "" {
		return tools, nil
	}
	if err := os.MkdirAll(binDir, 0755); err != nil {
		return tools, err
	}

	ytDlp, fetch := chooseTool(binDir, tools.YtDlp, "yt-dlp")
	if fetch && !fileIsWhole(ytDlp, 5<<20) {
		if err := ensureAssets(ctx, []asset{{ytDlpURL, ytDlp, 5 << 20}}, progress); err != nil {
			return tools, err
		}
	}
	tools.YtDlp = ytDlp

	return tools, nil
}

// EnsureAudioTools takes the chain EnsureDownloader returned and fills in ffmpeg and ffprobe, fetching
// them only when the caller has not configured them and the machine does not already have them.
//
// Separate from the downloader, and therefore later, because these two are worth fetching only once a
// transcription is actually going to happen: they arrive in an archive far larger than the two files
// kept from it, so a video refused for its length, or in a language no model handles, must reach that
// refusal without pulling a hundred megabytes of ffmpeg on the way.
func EnsureAudioTools(ctx context.Context, binDir string, tools Toolchain, progress func(string)) (Toolchain, error) {
	if binDir == "" {
		return tools, nil
	}
	if err := os.MkdirAll(binDir, 0755); err != nil {
		return tools, err
	}

	ffmpeg, fetchFFmpeg := chooseTool(binDir, tools.FFmpeg, "ffmpeg")
	ffprobe, fetchFFprobe := chooseTool(binDir, tools.FFprobe, "ffprobe")

	// ffmpeg and ffprobe arrive together, in a zip far larger than the two binaries worth keeping, and
	// they are fetched as a pair so the two always come from the same build.
	if (fetchFFmpeg && !fileIsWhole(ffmpeg, 1)) || (fetchFFprobe && !fileIsWhole(ffprobe, 1)) {
		if progress != nil {
			progress("Fetching the audio tools…")
		}
		archive := filepath.Join(binDir, "ffmpeg.zip")
		if err := download.FileContext(ctx, archive, ffmpegURL, nil); err != nil {
			return tools, fmt.Errorf("the audio tools could not be fetched: %w", err)
		}
		if err := extractZipNames(archive, binDir, map[string]bool{
			exeName("ffmpeg"): true, exeName("ffprobe"): true,
		}); err != nil {
			return tools, fmt.Errorf("the audio tools could not be unpacked: %w", err)
		}
		_ = os.Remove(archive)
		ffmpeg = managedPath(binDir, "ffmpeg")
		ffprobe = managedPath(binDir, "ffprobe")
	}
	tools.FFmpeg, tools.FFprobe = ffmpeg, ffprobe

	return tools, nil
}

// chooseTool is the precedence every tool here obeys: a path the caller configured, then a copy the
// machine already has, then the launcher's own — and only that last one is something to fetch. A
// configured path is trusted as it is: a probe points at its own copies, and a path that turns out to
// be wrong should fail loudly when it is used rather than quietly pull another copy.
//
// The second return value says whether the path is the launcher's own copy — that is, whether a file
// that is missing or too small means "fetch it" rather than "fail where you are".
func chooseTool(binDir, configuredPath, name string) (string, bool) {
	if configuredPath != "" {
		return configuredPath, false
	}
	if found := onPath(name); found != "" {
		return found, false
	}

	return managedPath(binDir, name), true
}

// managedPath is where the launcher keeps its own copy of a host binary: the same folder llama-server
// and the transcriber live in, so there is one place a machine's tools come from.
func managedPath(binDir, name string) string {
	return filepath.Join(binDir, exeName(name))
}

// onPath is the binary the machine already provides at that name, so nothing is downloaded twice.
func onPath(name string) string {
	if found, err := exec.LookPath(name); err == nil {
		return found
	}

	return ""
}

// Ensure fetches the speech detector and the model this language needs. A language no model handles
// needs nothing: the watch falls back to the video's own captions, which is a valid outcome and not a
// reason to download a gigabyte.
func (m ASRModels) Ensure(ctx context.Context, language string, progress func(string)) error {
	set, ok := m.modelsFor(language)
	if !ok {
		return nil
	}

	detector := []asset{{vadURL, m.vadModelPath(), 500 << 10}}

	// The runtime carries the transcriber and the detector we call, so it comes before either is used.
	if _, err := os.Stat(m.runnerPath()); err != nil {
		if err := os.MkdirAll(filepath.Join(m.Base, "sherpa-onnx"), 0755); err != nil {
			return err
		}
		if err := ensureAssets(ctx, append(detector, asset{sherpaURL, filepath.Join(m.Base, "runtime.tar.bz2"), 15 << 20}), progress); err != nil {
			return err
		}
		archive := filepath.Join(m.Base, "runtime.tar.bz2")
		if progress != nil {
			progress("Unpacking the transcriber…")
		}
		if err := extractTarBz2(archive, filepath.Join(m.Base, "sherpa-onnx")); err != nil {
			return fmt.Errorf("the transcriber could not be unpacked: %w", err)
		}
		_ = os.Remove(archive)
	} else if err := ensureAssets(ctx, detector, progress); err != nil {
		return err
	}

	base := parakeetEN
	if set.Dir != filepath.Join(m.Base, "parakeet-en") {
		base = parakeetML
	}
	model := []asset{
		{base + "/encoder.int8.onnx", set.Encoder, 600 << 20},
		{base + "/decoder.int8.onnx", set.Decoder, 5 << 20},
		{base + "/joiner.int8.onnx", set.Joiner, 1 << 20},
		{base + "/tokens.txt", set.Tokens, 1 << 10},
	}

	return ensureAssets(ctx, model, progress)
}

// ensureAssets fetches what is missing or too small to be whole, reporting which file it is on so a
// first watch that has to pull a gigabyte can say so.
func ensureAssets(ctx context.Context, assets []asset, progress func(string)) error {
	for index, item := range assets {
		if fileIsWhole(item.path, item.min) {
			continue
		}
		if err := os.MkdirAll(filepath.Dir(item.path), 0755); err != nil {
			return err
		}

		name := filepath.Base(item.path)
		onProgress := func(pct float64) {
			if progress != nil {
				progress(fmt.Sprintf("Fetching %s (%d of %d) — %.0f%%", name, index+1, len(assets), pct))
			}
		}
		if progress != nil {
			onProgress(0)
		}
		if err := download.FileContext(ctx, item.path, item.url, onProgress); err != nil {
			return fmt.Errorf("%s could not be fetched: %w", name, err)
		}
		if !fileIsWhole(item.path, item.min) {
			return fmt.Errorf("%s came back incomplete", name)
		}
	}

	return nil
}

// fileIsWhole says whether a file is there and big enough to be the thing it claims to be. A file
// smaller than its floor is a broken or half-finished download, not something to keep.
func fileIsWhole(path string, min int64) bool {
	st, err := os.Stat(path)

	return err == nil && st.Size() >= min
}

func exeName(base string) string {
	if filepath.Ext(base) == ".exe" {
		return base
	}

	return base + ".exe"
}

func extractTarBz2(archive, dest string) error {
	file, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer file.Close()

	return extractTar(bzip2.NewReader(file), dest)
}

// extractTar unpacks a tar stream, dropping the single leading directory every release archive carries.
// Doing this in process rather than shelling out to tar is deliberate: GNU tar reads a `C:/…` argument
// to -C as a remote host and dies, which is exactly how the first attempt at this failed.
func extractTar(source io.Reader, dest string) error {
	reader := tar.NewReader(source)
	root := filepath.Clean(dest)

	for {
		header, err := reader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}

		name := stripFirstComponent(header.Name)
		if name == "" {
			continue
		}
		target := filepath.Join(root, filepath.FromSlash(name))
		if !strings.HasPrefix(strings.ToLower(target), strings.ToLower(root)) {
			continue // an entry that would land outside the destination
		}

		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := writeEntry(target, reader); err != nil {
				return err
			}
		}
	}

	return nil
}

// extractZipNames unpacks only the named entries, matched on the base name. The ffmpeg build is a zip
// of hundreds of megabytes and the watch needs two files out of it.
func extractZipNames(archive, dest string, wanted map[string]bool) error {
	reader, err := zip.OpenReader(archive)
	if err != nil {
		return err
	}
	defer reader.Close()

	for _, entry := range reader.File {
		name := filepath.Base(entry.Name)
		if !wanted[strings.ToLower(name)] && !wanted[name] {
			continue
		}
		source, err := entry.Open()
		if err != nil {
			return err
		}
		err = writeEntry(filepath.Join(dest, name), source)
		source.Close()
		if err != nil {
			return err
		}
	}

	return nil
}

func writeEntry(target string, source io.Reader) error {
	if err := os.MkdirAll(filepath.Dir(target), 0755); err != nil {
		return err
	}
	out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0755)
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, source)

	return err
}

func stripFirstComponent(name string) string {
	trimmed := strings.TrimPrefix(filepath.ToSlash(name), "./")
	parts := strings.SplitN(trimmed, "/", 2)
	if len(parts) < 2 {
		return ""
	}

	return parts[1]
}

// SupportedLanguages is what the settings side needs to say which videos can be transcribed here:
// English by the English model, the rest of the list by the multilingual one.
func SupportedLanguages() []string {
	languages := make([]string, 0, len(multilingualLanguages)+1)
	languages = append(languages, "en")
	for language := range multilingualLanguages {
		if language != "en" {
			languages = append(languages, language)
		}
	}

	return languages
}
