package launcher

import (
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"localsy/internal/video"
)

// The launcher's side of a watch. The web layer starts one, polls it for the spinner's text, and
// cancels it when the browser goes away (VideoWatchClient / VideoWatchRunner on the PHP side).
//
// Every response is a job snapshot, because that is what the poller reads: id, stage, status, the
// step/total counters, the error, and — once there is one — the result.

// videoCapability is what the web layer needs to describe video watching honestly before anything is
// watched: which pieces are already here, and which would be fetched on first use.
type videoCapability struct {
	Available          bool     `json:"available"`
	Languages          []string `json:"languages"`
	MaxDurationSeconds float64  `json:"max_duration_seconds"`
	Downloader         bool     `json:"downloader"`
	AudioTools         bool     `json:"audio_tools"`
	Detector           bool     `json:"detector"`
	ModelEnglish       bool     `json:"model_english"`
	ModelMultilingual  bool     `json:"model_multilingual"`
	FetchesOnDemand    bool     `json:"fetches_on_demand"`
}

func (h *modelsHandler) handleVideoWatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		writeJSON(w, 405, map[string]string{"error": "method not allowed"})
		return
	}
	if h.videos == nil {
		writeJSON(w, 503, map[string]string{"error": "video watching is not available in this build"})
		return
	}

	var req struct {
		URL string `json:"url"`
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<16)).Decode(&req); err != nil || strings.TrimSpace(req.URL) == "" {
		writeJSON(w, 400, map[string]string{"error": "url required"})
		return
	}

	job, err := h.videos.Start(req.URL)
	if err != nil {
		writeJSON(w, 400, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, 202, job.Snapshot())
}

// handleVideoJob serves both the status poll and the cancel call, so the two paths cannot drift.
func (h *modelsHandler) handleVideoJob(w http.ResponseWriter, r *http.Request, rest string) {
	if h.videos == nil {
		writeJSON(w, 503, map[string]string{"error": "video watching is not available in this build"})
		return
	}

	cancel := false
	id := rest
	if strings.HasSuffix(rest, "/cancel") {
		cancel = true
		id = strings.TrimSuffix(rest, "/cancel")
	}
	id = strings.Trim(strings.TrimSpace(id), "/")
	if id == "" {
		writeJSON(w, 400, map[string]string{"error": "job id required"})
		return
	}

	if cancel {
		if r.Method != "POST" {
			writeJSON(w, 405, map[string]string{"error": "method not allowed"})
			return
		}
		stopped := h.videos.Cancel(id)
		status := "cancelling"
		if !stopped {
			status = "not running"
		}
		writeJSON(w, 200, map[string]interface{}{"ok": stopped, "status": status, "id": id})
		return
	}

	if r.Method != "GET" {
		writeJSON(w, 405, map[string]string{"error": "method not allowed"})
		return
	}

	job, ok := h.videos.Get(id)
	if !ok {
		writeJSON(w, 404, map[string]string{"error": "no such watch"})
		return
	}

	writeJSON(w, 200, job.Snapshot())
}

func (h *modelsHandler) handleVideoCapability(w http.ResponseWriter, r *http.Request) {
	if r.Method != "GET" {
		writeJSON(w, 405, map[string]string{"error": "method not allowed"})
		return
	}

	workDir := h.workDir()
	binDir := filepath.Join(workDir, "bin")
	models := video.DefaultASRModels(workDir)

	present := func(path string) bool {
		_, err := os.Stat(path)

		return err == nil
	}
	found := func(name, inBin string) bool {
		return present(filepath.Join(binDir, inBin)) || video.BinaryPresent(name)
	}

	writeJSON(w, 200, videoCapability{
		Available:          h.videos != nil,
		Languages:          video.SupportedLanguages(),
		MaxDurationSeconds: video.MaxDurationSeconds(workDir),
		Downloader:         found("yt-dlp", "yt-dlp.exe"),
		AudioTools:         found("ffmpeg", "ffmpeg.exe"),
		Detector:           models.VadInstalled(),
		ModelEnglish:       models.Installed("en"),
		ModelMultilingual:  models.Installed(multilingualProbe),
		FetchesOnDemand:    true,
	})
}

// multilingualProbe is any language the multilingual model covers. Asking through the same router the
// watch uses means the answer cannot disagree with what a watch would actually do.
const multilingualProbe = "de"
