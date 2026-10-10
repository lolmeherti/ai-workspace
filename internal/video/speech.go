package video

import (
	"context"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// Speech detection is not this package's problem to solve. Silero VAD decides where speech is; this file
// only asks it and reads the answer back. The hand-rolled loudness threshold that used to live here is
// gone: a level test answers "is there energy", not "is there speech", and tuning it was guesswork.

// vadSegment matches the line the VAD runner prints per speech region when asked to be verbose, e.g.
// "12.870 -- 13.804".
var vadSegment = regexp.MustCompile(`^\s*([0-9]+(?:\.[0-9]+)?)\s+--\s+([0-9]+(?:\.[0-9]+)?)\s*$`)

// parseVADSegments reads the speech regions out of the VAD runner's output.
func parseVADSegments(output string) []stretch {
	var regions []stretch
	for _, line := range strings.Split(output, "\n") {
		match := vadSegment.FindStringSubmatch(line)
		if match == nil {
			continue
		}

		start, err := strconv.ParseFloat(match[1], 64)
		if err != nil {
			continue
		}
		end, err := strconv.ParseFloat(match[2], 64)
		if err != nil || end <= start {
			continue
		}

		regions = append(regions, stretch{start, end})
	}

	return regions
}

// findSpeech asks Silero VAD where the speech is. The runner also writes a silence-stripped copy of the
// audio, which this package does not want; it lands in the workspace and is deleted with everything else.
func findSpeech(ctx context.Context, tools Toolchain, models ASRModels, ws *Workspace, wav string) ([]stretch, error) {
	if !models.VadInstalled() {
		return nil, fmt.Errorf("the speech detector is not installed")
	}

	stripped, err := ws.File("vad-stripped.wav")
	if err != nil {
		return nil, err
	}

	out := RunChild(ctx, models.vadRunnerPath(),
		"--silero-vad-model="+models.vadModelPath(),
		"--vad-num-threads="+strconv.Itoa(threadCount()),
		"--vad-debug=true",
		wav, stripped)
	if out.Failed() {
		return nil, fmt.Errorf("the speech detector failed: %s", out.Reason())
	}

	// The runner is chatty and which stream it uses is its business; the regions are the lines that look
	// like a span.
	regions := parseVADSegments(out.Stdout + "\n" + out.Stderr)

	return regions, nil
}
