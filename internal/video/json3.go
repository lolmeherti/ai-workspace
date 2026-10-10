package video

import (
	"encoding/json"
	"fmt"
	"strings"
)

// json3 is the caption format the browser's player fetches for a track (YouTube's timedtext endpoint with
// fmt=json3). It is the payload the watch pipeline uses when the browser is the caption source, because the
// player is the only client YouTube still serves the subtitle bytes to — a direct fetch of the same track
// URL comes back 200 with an empty body (measured 2026-10-09).
//
// Shape, from a real track (1010 events for a 21-minute video):
//
//	{"wireMagic":"pb3","events":[
//	  {"tStartMs":0,"dDurationMs":1286799,"id":1,"wpWinPosId":1,"wsWinStyleId":1},        <- window definition
//	  {"tStartMs":160,"dDurationMs":4639,"segs":[{"utf8":"Okay,"},{"utf8":" this","tOffsetMs":320}...]},
//	  {"tStartMs":2869,"dDurationMs":1930,"aAppend":1,"segs":[{"utf8":"\n"}]}            <- line break, no words
//	]}
//
// A window definition has no segments and carries no words; an append marker carries only a line break. Both
// are skipped, so what comes out is one cue per stretch of speech, with the times the track itself states.
type json3Event struct {
	StartMs    int64 `json:"tStartMs"`
	DurationMs int64 `json:"dDurationMs"`
	Append     int   `json:"aAppend"`
	Segs       []struct {
		Utf8      string `json:"utf8"`
		TOffsetMs int64  `json:"tOffsetMs"`
	} `json:"segs"`
}

type json3Track struct {
	WireMagic string       `json:"wireMagic"`
	Events    []json3Event `json:"events"`
}

// parseJSON3 turns a json3 caption track into the same cue list the VTT path produces, so everything
// downstream — word counts, coverage, the fusion pass — works on it unchanged.
func parseJSON3(body string) ([]cue, error) {
	if strings.TrimSpace(body) == "" {
		return nil, fmt.Errorf("the caption track is empty")
	}

	var track json3Track
	if err := json.Unmarshal([]byte(body), &track); err != nil {
		return nil, fmt.Errorf("the caption track is not readable: %w", err)
	}
	if len(track.Events) == 0 {
		return nil, fmt.Errorf("the caption track carries no events")
	}

	var cues []cue
	for _, event := range track.Events {
		if len(event.Segs) == 0 {
			continue
		}

		var text strings.Builder
		for _, seg := range event.Segs {
			text.WriteString(seg.Utf8)
		}
		spoken := strings.Join(strings.Fields(text.String()), " ")
		if spoken == "" {
			continue
		}

		start := float64(event.StartMs) / 1000
		cues = append(cues, cue{
			start: start,
			end:   start + float64(event.DurationMs)/1000,
			text:  spoken,
		})
	}

	if len(cues) == 0 {
		return nil, fmt.Errorf("the caption track carries no spoken cues")
	}

	return cues, nil
}
