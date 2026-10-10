package launcher

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"localsy/internal/bridge"
	"localsy/internal/video"
)

// browserCaptions asks the user's own browser for a watch page's caption track.
//
// The page's player is the only client YouTube still serves the subtitle bytes to: the same track URL
// fetched directly comes back 200 with an empty body (measured 2026-10-09), which is why the downloader
// listed 21 tracks and fetched no segments for a video whose captions the player itself had just loaded.
// The browser is therefore the caption source, and the downloader stays as the fallback behind it.
//
// Nothing about this plays or focuses anything: the extension opens a background tab, switches captions on
// and reads the response the player received. A fetch that cannot happen — no extension connected, a page
// that is not a watch page, a challenge screen — is an error and nothing more, and the caller degrades.
func browserCaptions(relay *bridge.Relay) video.BrowserCaptionSource {
	return func(ctx context.Context, url string) (video.BrowserCaptions, error) {
		if relay == nil || !relay.IsConnected() {
			return video.BrowserCaptions{}, fmt.Errorf("the browser bridge is not connected")
		}

		requestID := fmt.Sprintf("captions-%x", time.Now().UnixNano())
		result, err := relay.Fetch(ctx, url, requestID)
		if err != nil {
			return video.BrowserCaptions{}, fmt.Errorf("the browser could not be asked: %w", err)
		}
		if result == nil || result.Status != "success" || result.Content == nil {
			reason := ""
			if result != nil {
				reason = strings.TrimSpace(result.Status + " " + result.Error)
			}
			return video.BrowserCaptions{}, fmt.Errorf("the browser returned no page (%s)", reason)
		}

		var payload browserCaptionPayload
		if err := json.Unmarshal(*result.Content, &payload); err != nil {
			return video.BrowserCaptions{}, fmt.Errorf("the browser's answer was not readable: %w", err)
		}
		if payload.Captions == nil || strings.TrimSpace(payload.Captions.Text) == "" {
			return video.BrowserCaptions{}, fmt.Errorf("the page's player supplied no caption track")
		}

		track := payload.track()
		kind := "manual"
		if strings.EqualFold(track.Kind, "asr") {
			kind = "auto"
		}

		return video.BrowserCaptions{
			Kind:     kind,
			Language: track.LanguageCode,
			Track:    track.VssID,
			JSON:     payload.Captions.Text,
		}, nil
	}
}

// browserCaptionPayload is the shape the extension's watch-page answer carries: the track the page's player
// fetched, plus the measurement it came with (which names the track the player had switched on).
type browserCaptionPayload struct {
	Captions *struct {
		ContentType string   `json:"ct"`
		Bytes       int      `json:"bytes"`
		Params      []string `json:"params"`
		Text        string   `json:"text"`
	} `json:"captions"`
	Probe *struct {
		CC struct {
			ActiveAfter struct {
				Track browserCaptionTrack `json:"track"`
			} `json:"active_after"`
		} `json:"cc"`
	} `json:"probe"`
}

type browserCaptionTrack struct {
	LanguageCode string `json:"languageCode"`
	Kind         string `json:"kind"`
	VssID        string `json:"vss_id"`
}

func (p browserCaptionPayload) track() browserCaptionTrack {
	if p.Probe == nil {
		return browserCaptionTrack{}
	}

	return p.Probe.CC.ActiveAfter.Track
}
