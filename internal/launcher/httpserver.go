package launcher

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"localsy/internal/bridge"
	"localsy/internal/llama"
	"localsy/internal/models"
	"localsy/internal/util"
)

func StartHTTPServer(defs map[string]models.ModelDefinition, hw models.Hardware, binDir, modelDir string, relay *bridge.Relay) {
	handler := &modelsHandler{
		defs:     defs,
		hw:       hw,
		binDir:   binDir,
		modelDir: modelDir,
		relay:    relay,
	}

	go func() {
		if err := http.ListenAndServe(":9876", handler); err != nil {
			util.LogPrint("HTTP server error on :9876: %v\n", err)
		}
	}()
}

type modelsHandler struct {
	defs     map[string]models.ModelDefinition
	hw       models.Hardware
	binDir   string
	modelDir string
	relay    *bridge.Relay
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
	case "/bridge/status":
		h.handleBridgeStatus(w, r)
	case "/bridge/fetch":
		h.handleBridgeFetch(w, r)
	case "/bridge/search":
		h.handleBridgeSearch(w, r)
	default:
		http.NotFound(w, r)
	}
}

type modelSwitchRequest struct {
	ModelID   string `json:"model_id"`
	ModelName string `json:"model_name"`
	CtxSize   int    `json:"ctx_size,omitempty"`
}

func (h *modelsHandler) handleGetModels(w http.ResponseWriter, _ *http.Request) {
	type profileEntry struct {
		ModelID    string  `json:"model_id"`
		Name       string  `json:"name"`
		ProfileID  string  `json:"profile_id"`
		VRAMGroup  string  `json:"vram_group"`
		VRAMMin    float64 `json:"vram_min"`
		CtxSize    int     `json:"ctx_size"`
		Vision     bool    `json:"vision"`
		Speculative bool   `json:"speculative"`
	}

	entries := make([]profileEntry, 0)
	for id, def := range h.defs {
		if def.Model.File == "" || def.Model.URL == "" {
			continue
		}
		speculative := def.Speculative != nil
		if !speculative {
			for _, p := range def.Profiles {
				if p.Speculative != nil {
					speculative = true
					break
				}
			}
		}
		for pid, p := range def.Profiles {
			if p.Requirements.VRAMMin > h.hw.VRAMGB {
				continue
			}
			entries = append(entries, profileEntry{
				ModelID:    id,
				Name:       def.Name,
				ProfileID:  pid,
				VRAMGroup:  vramGroupLabel(p.Requirements.VRAMMin),
				VRAMMin:    p.Requirements.VRAMMin,
				CtxSize:    p.CtxSize,
				Vision:     def.Capabilities.Vision,
				Speculative: speculative,
			})
		}
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(entries)
}

func vramGroupLabel(vramMin float64) string {
	switch {
	case vramMin >= 30:
		return "32GB+"
	case vramMin >= 22:
		return "24GB+"
	case vramMin >= 14:
		return "16GB+"
	case vramMin >= 10:
		return "12GB+"
	case vramMin >= 6:
		return "8GB+"
	default:
		return "Any"
	}
}

func (h *modelsHandler) handleModelSwitch(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(r.Body)
	if err != nil {
		writeJSON(w, 400, map[string]string{"error": "bad request"})
		return
	}

	var req modelSwitchRequest
	if err := json.Unmarshal(body, &req); err != nil || (req.ModelID == "" && req.ModelName == "") {
		writeJSON(w, 400, map[string]string{"error": "invalid payload"})
		return
	}

	if req.ModelID == "" && req.ModelName != "" {
		for id, def := range h.defs {
			if def.Name == req.ModelName {
				req.ModelID = id
				break
			}
		}
		if req.ModelID == "" {
			writeJSON(w, 404, map[string]string{"error": "model not found: " + req.ModelName})
			return
		}
	}

	resolved, err := models.ResolveModel(req.ModelID, h.defs, h.hw, h.modelDir, nil)
	if err != nil {
		writeJSON(w, 404, map[string]string{"error": err.Error()})
		return
	}

	if req.CtxSize > 0 {
		resolved.CtxSize = req.CtxSize
	}

	llama.KillIfRunning(&LlamaProcess)

	LlamaProcess = llama.StartServerWithFallback(h.binDir, resolved)

	writeJSON(w, 200, map[string]interface{}{
		"status":   "ok",
		"name":     resolved.Name,
		"ctx_size": resolved.CtxSize,
	})

	workDir := filepath.Dir(h.modelDir)
	envPath := filepath.Join(workDir, ".env")
	if existing, err := os.ReadFile(envPath); err == nil {
		lines := strings.Split(string(existing), "\n")
		updated := make([]string, 0, len(lines))
		hasModelID, hasModelName, hasCtxSize := false, false, false
		for _, line := range lines {
			trimmed := strings.TrimSpace(line)
			if strings.HasPrefix(trimmed, "LLM_MODEL_ID=") {
				updated = append(updated, "LLM_MODEL_ID="+req.ModelID)
				hasModelID = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_MODEL_NAME=") {
				updated = append(updated, "LLM_MODEL_NAME="+resolved.Name)
				hasModelName = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_CTX_SIZE=") {
				updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(resolved.CtxSize))
				hasCtxSize = true
				continue
			}
			updated = append(updated, line)
		}
		if !hasModelID {
			updated = append(updated, "LLM_MODEL_ID="+req.ModelID)
		}
		if !hasModelName {
			updated = append(updated, "LLM_MODEL_NAME="+resolved.Name)
		}
		if !hasCtxSize && resolved.CtxSize > 0 {
			updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(resolved.CtxSize))
		}
		_ = os.WriteFile(envPath, []byte(strings.Join(updated, "\n")), 0644)
	}
}

// ── bridge endpoints ──

func (h *modelsHandler) handleBridgeStatus(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, 200, map[string]bool{
		"connected": h.relay.IsConnected(),
	})
}

func (h *modelsHandler) handleBridgeFetch(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		writeJSON(w, 405, map[string]string{"error": "method not allowed"})
		return
	}

	var req struct {
		URL       string `json:"url"`
		RequestID string `json:"request_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.URL == "" {
		writeJSON(w, 400, map[string]string{"error": "url required"})
		return
	}
	if req.RequestID == "" {
		req.RequestID = fmt.Sprintf("%x", time.Now().UnixNano())
	}

	ctx, cancel := context.WithTimeout(r.Context(), 90*time.Second)
	defer cancel()

	result, _ := h.relay.Fetch(ctx, req.URL, req.RequestID)
	writeJSON(w, 200, result)
}

func (h *modelsHandler) handleBridgeSearch(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		writeJSON(w, 405, map[string]string{"error": "method not allowed"})
		return
	}

	var req struct {
		Query     string `json:"query"`
		RequestID string `json:"request_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.Query == "" {
		writeJSON(w, 400, map[string]string{"error": "query required"})
		return
	}
	if req.RequestID == "" {
		req.RequestID = fmt.Sprintf("s-%x", time.Now().UnixNano())
	}

	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	result, _ := h.relay.Search(ctx, req.Query, req.RequestID)
	writeJSON(w, 200, result)
}

// ── helpers ──

func writeJSON(w http.ResponseWriter, status int, v interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func isAbsolute(p string) bool {
	return len(p) >= 3 && p[1] == ':' || (len(p) > 0 && p[0] == '/')
}
