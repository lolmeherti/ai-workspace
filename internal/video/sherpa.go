package video

import (
	"encoding/json"
	"errors"
	"strings"
)

// sherpaGap is the pause that starts a new segment. sherpa-onnx hands back per-token times, not
// segments; punctuation alone is not enough, because a segment a reader can navigate to is bounded
// by a breath, not by a comma.
const sherpaGap = 0.8

type sherpaLine struct {
	Text       string    `json:"text"`
	Tokens     []string  `json:"tokens"`
	Timestamps []float64 `json:"timestamps"`
}

// Transcript is the decoder's own view of one stretch of audio: its tokens and the time of each.
type Transcript struct {
	Tokens []string
	Times  []float64
	Text   string
}

// jsonObjects returns every top-level JSON object in a stream that also carries human-readable noise,
// in the order they were printed. The decoder prints one object per input file, so the count and the
// order are what tie each transcript back to the slice it came from.
func jsonObjects(text string) [][]byte {
	var objects [][]byte
	depth, start := 0, -1
	inString, escaped := false, false

	for index := 0; index < len(text); index++ {
		char := text[index]

		if inString {
			switch {
			case escaped:
				escaped = false
			case char == '\\':
				escaped = true
			case char == '"':
				inString = false
			}
			continue
		}

		switch char {
		case '"':
			if depth > 0 {
				inString = true
			}
		case '{':
			if depth == 0 {
				start = index
			}
			depth++
		case '}':
			if depth > 0 {
				depth--
				if depth == 0 && start >= 0 {
					objects = append(objects, []byte(text[start:index+1]))
					start = -1
				}
			}
		}
	}

	return objects
}

// TranscriptsFromCLI reads one transcript per input file, in the order the files were given. A whole
// batch of slices is handed to the decoder in a single run, so a single model load covers all of
// them; the caller matches transcripts to slices by position.
func TranscriptsFromCLI(stdout string) ([]Transcript, error) {
	var out []Transcript
	for _, object := range jsonObjects(stdout) {
		// A result is recognised by its shape, not by its content: a slice with no speech in it comes
		// back as an empty result and must still be counted, or the transcripts stop lining up with the
		// slices they belong to. Configuration dumps carry neither key.
		var probe map[string]json.RawMessage
		if err := json.Unmarshal(object, &probe); err != nil {
			continue
		}
		_, hasTokens := probe["tokens"]
		_, hasText := probe["text"]
		if !hasTokens && !hasText {
			continue
		}

		var parsed sherpaLine
		if err := json.Unmarshal(object, &parsed); err != nil {
			continue
		}
		out = append(out, transcriptOf(parsed))
	}

	if len(out) == 0 {
		return nil, errors.New("video: no transcript object in the decoder output")
	}

	return out, nil
}

// TokensFromCLI reads the transcript of a single-file run — the last object the decoder printed.
func TokensFromCLI(stdout string) (Transcript, error) {
	all, err := TranscriptsFromCLI(stdout)
	if err != nil {
		return Transcript{}, err
	}

	return all[len(all)-1], nil
}

func transcriptOf(parsed sherpaLine) Transcript {
	count := len(parsed.Tokens)
	if len(parsed.Timestamps) < count {
		count = len(parsed.Timestamps)
	}

	return Transcript{
		Tokens: parsed.Tokens[:count],
		Times:  parsed.Timestamps[:count],
		Text:   strings.TrimSpace(parsed.Text),
	}
}

// SegmentsFromCLI turns a single-file run's stdout into timestamped segments. Batching is handled by
// TranscriptsFromCLI; this reader exists for callers that hold one file's output, and takes the last
// object the decoder printed.
func SegmentsFromCLI(stdout string) ([]Segment, error) {
	parsed, err := TokensFromCLI(stdout)
	if err != nil {
		return nil, err
	}

	segments := GroupTokens(parsed.Tokens, parsed.Times)
	if len(segments) == 0 && parsed.Text != "" {
		return []Segment{{Start: 0, End: 0, Text: parsed.Text}}, nil
	}

	return segments, nil
}

// GroupTokens rebuilds segments from tokens and their times.
func GroupTokens(tokens []string, times []float64) []Segment {
	count := len(tokens)
	if len(times) < count {
		count = len(times)
	}
	if count == 0 {
		return nil
	}

	var segments []Segment
	start := times[0]
	parts := make([]string, 0, 16)
	flush := func(end float64) {
		text := strings.TrimSpace(strings.Join(parts, ""))
		if text != "" {
			segments = append(segments, Segment{Start: start, End: end, Text: text})
		}
		parts = parts[:0]
	}

	for i := 0; i < count; i++ {
		if i > 0 && times[i]-times[i-1] > sherpaGap {
			flush(times[i-1])
			start = times[i]
		}
		parts = append(parts, tokens[i])
	}
	flush(times[count-1] + 0.5)

	return segments
}
