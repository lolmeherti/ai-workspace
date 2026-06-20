package launcher

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"localsy/internal/download"
	"localsy/internal/env"
	"localsy/internal/llama"
	"localsy/internal/models"
	"localsy/internal/util"
)

func StartHTTPServer(tiers map[string]models.Tier, binDir, modelDir, searxngDir string) {
	handler := &modelsHandler{
		tiers:      tiers,
		binDir:     binDir,
		modelDir:   modelDir,
		searxngDir: searxngDir,
	}

	go func() {
		if err := http.ListenAndServe(":9876", handler); err != nil {
			util.LogPrint("HTTP server error on :9876: %v\n", err)
		}
	}()
}

type modelsHandler struct {
	tiers      map[string]models.Tier
	binDir     string
	modelDir   string
	searxngDir string
}

func (h *modelsHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	switch path {
	case "/api/models":
		h.handleGetModels(w, r)
	case "/api/model-switch":
		if r.Method != "POST" {
			http.Error(w, "method not allowed", 405)
			return
		}
		h.handleModelSwitch(w, r)
	default:
		http.NotFound(w, r)
	}
}

type modelSwitchRequest struct {
	ModelName string `json:"model_name"`
	CtxSize   int    `json:"ctx_size,omitempty"`
}

func (h *modelsHandler) handleGetModels(w http.ResponseWriter, _ *http.Request) {
	type modelEntry struct {
		Name       string `json:"name"`
		File       string `json:"file"`
		URL        string `json:"url"`
		CtxSize    int    `json:"ctx_size,omitempty"`
		MMProjFile string `json:"mmproj_file,omitempty"`
	}

	entries := make([]modelEntry, 0, len(h.tiers))
	for _, t := range h.tiers {
		if t.Name == "" || t.File == "" {
			continue
		}
		entries = append(entries, modelEntry{
			Name:       t.Name,
			File:       t.File,
			URL:        t.URL,
			CtxSize:    t.CtxSize,
			MMProjFile: t.MMProjFile,
		})
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(entries)
}

func (h *modelsHandler) handleModelSwitch(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(r.Body)
	if err != nil {
		writeJSON(w, 400, map[string]string{"error": "bad request"})
		return
	}

	var req modelSwitchRequest
	if err := json.Unmarshal(body, &req); err != nil || req.ModelName == "" {
		writeJSON(w, 400, map[string]string{"error": "invalid payload"})
		return
	}

	var tier models.Tier
	found := false
	for _, t := range h.tiers {
		if t.Name == req.ModelName {
			tier = t
			found = true
			break
		}
	}
	if !found {
		writeJSON(w, 404, map[string]string{"error": fmt.Sprintf("model %q not found", req.ModelName)})
		return
	}

	modelPath := tier.File
	if !isAbsolute(tier.File) {
		modelPath = h.modelDir + "/" + tier.File
	}

	ctxSize := req.CtxSize
	if ctxSize <= 0 {
		ctxSize = tier.CtxSize
	}

	// Download if missing
	if _, err := os.Stat(modelPath); os.IsNotExist(err) {
		tmpPath := modelPath + ".tmp"
		if err := download.File(tmpPath, tier.URL, nil); err != nil {
			writeJSON(w, 500, map[string]string{"error": "download failed: " + err.Error()})
			return
		}
		os.Rename(tmpPath, modelPath)
	}

	// Download mmproj if configured but missing
	if tier.MMProjFile != "" {
		mmprojPath := h.modelDir + "/" + tier.MMProjFile
		if _, err := os.Stat(mmprojPath); os.IsNotExist(err) && tier.MMProjURL != "" {
			tmpMmproj := mmprojPath + ".tmp"
			if err := download.File(tmpMmproj, tier.MMProjURL, nil); err != nil {
				log.Printf("[model-switch] failed to download mmproj %s: %v", tier.MMProjFile, err)
				// Don't fail the switch — continue without mmproj
			} else {
				os.Rename(tmpMmproj, mmprojPath)
			}
		}
	}

	// Kill existing process
	if LlamaProcess != nil && LlamaProcess.Process != nil {
		LlamaProcess.Process.Kill()
		LlamaProcess.Wait()
		LlamaProcess = nil
	}

	// Start new server with same mmproj if it exists
	mmprojPath := ""
	if tier.MMProjFile != "" {
		mmprojPath = h.modelDir + "/" + tier.MMProjFile
	}

	LlamaProcess = llama.StartServer(h.binDir, modelPath, mmprojPath, tier, ctxSize)

	// Wait for llama to be ready before responding
	waitLlamaReady()

	// Persist selection to env so it survives restart
	workDir := filepath.Dir(h.modelDir)
	envPath := filepath.Join(workDir, ".env")
	if existing, err := os.ReadFile(envPath); err == nil {
		lines := strings.Split(string(existing), "\n")
		updated := make([]string, 0, len(lines))
		hasCtxSize := false
		for _, line := range lines {
			trimmed := strings.TrimSpace(line)
			if strings.HasPrefix(trimmed, "LLM_MODEL_NAME=") {
				updated = append(updated, "LLM_MODEL_NAME="+env.QuoteEnvValue(tier.Name))
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_CTX_SIZE=") {
				updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(ctxSize))
				hasCtxSize = true
				continue
			}
			updated = append(updated, line)
		}
		if !hasCtxSize && ctxSize > 0 {
			updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(ctxSize))
		}
		_ = os.WriteFile(envPath, []byte(strings.Join(updated, "\n")), 0644)
	}

	writeJSON(w, 200, map[string]interface{}{
		"status": "ok",
		"name":   tier.Name,
		"ctx_size": ctxSize,
	})
}

func writeJSON(w http.ResponseWriter, status int, v interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

// waitLlamaReady blocks until llama is ready (max 10s).
func waitLlamaReady() {
	client := http.Client{Timeout: 2 * time.Second}
	for i := 0; i < 10; i++ {
		resp, err := client.Get("http://127.0.0.1:1234/health")
		if err == nil && resp != nil {
			body := make([]byte, 512)
			n, _ := resp.Body.Read(body)
			resp.Body.Close()
			var status struct{ Status string }
			if json.Unmarshal(body[:n], &status) == nil && status.Status == "ok" {
				return
			}
		}
		time.Sleep(1 * time.Second)
	}
}

func isAbsolute(p string) bool {
	return len(p) >= 3 && p[1] == ':' || (len(p) > 0 && p[0] == '/')
}
