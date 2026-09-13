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
	"sync"
	"time"

	"localsy/internal/bridge"
	"localsy/internal/env"
	"localsy/internal/gguf"
	"localsy/internal/gpu"
	"localsy/internal/llama"
	"localsy/internal/memcalc"
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

	// Precompute every model's max-ctx ceiling in the background so the settings
	// dropdown is honest (and fast) by the time the user opens it.
	go handler.warmModelHeaders()

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

	switchMu sync.Mutex
	sw       switchStatus

	// switchCancel aborts the in-flight switch's download when /api/model-switch/cancel
	// is called. Nil when no switch is in flight.
	switchCancel context.CancelFunc

	// headerMu guards headerCache: parsed GGUF headers cached once per model so
	// the settings dropdown can compute max-ctx without re-fetching headers.
	headerMu    sync.Mutex
	headerCache map[string]headerCacheEntry
}

// headerCacheEntry is a cached GGUF header (plus mmproj size) for one model,
// used to compute the max context that fits before the user sees any option.
type headerCacheEntry struct {
	header      *gguf.Header
	mmprojBytes uint64
}

// switchStatus is the observable state of an in-flight (or last completed)
// model switch. It is written by the background switch goroutine and read by
// /api/switch-status, so every access goes through the handler mutex.
type switchStatus struct {
	Active    bool      `json:"active"`
	ModelID   string    `json:"model_id"`
	Name      string    `json:"name"`
	CtxSize   int       `json:"ctx_size"`
	Stage     string    `json:"stage"`    // resolving | downloading | starting | loaded | error
	Progress  float64   `json:"progress"` // 0-100 (per-artifact during download)
	Detail    string    `json:"detail,omitempty"`
	Error     string    `json:"error,omitempty"`
	StartedAt time.Time `json:"started_at"`

	// Resolved per-model config the web layer persists alongside the model
	// identity (AISettingsController::handleSwitchStatus). Populated once the
	// switch reaches "starting".
	Sampling        string `json:"sampling"`
	RuntimePolicy   string `json:"runtime_policy"`
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
	case "/api/switch-status":
		h.handleSwitchStatus(w, r)
	case "/api/model-switch/cancel":
		h.handleCancelSwitch(w, r)
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
		ModelID     string  `json:"model_id"`
		Name        string  `json:"name"`
		ProfileID   string  `json:"profile_id"`
		VRAMGroup   string  `json:"vram_group"`
		VRAMMin     float64 `json:"vram_min"`
		CtxSize     int     `json:"ctx_size"`
		MaxCtx      int     `json:"max_ctx"`
		Vision      bool    `json:"vision"`
		Speculative bool    `json:"speculative"`
	}

	entries := make([]profileEntry, 0)
	total, _ := gpu.TotalVRAMBytes()
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
			ctx := p.CtxSize
			maxCtx := h.maxContextFor(id, p, total)
			if maxCtx > 0 && ctx > maxCtx {
				ctx = maxCtx
			}
			entries = append(entries, profileEntry{
				ModelID:     id,
				Name:        def.Name,
				ProfileID:   pid,
				VRAMGroup:   vramGroupLabel(p.Requirements.VRAMMin),
				VRAMMin:     p.Requirements.VRAMMin,
				CtxSize:     ctx,
				MaxCtx:      maxCtx,
				Vision:      def.Capabilities.Vision,
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

	// Validate up-front so a bad request fails fast instead of surfacing as a
	// background error after the client has already been told "switching".
	if err := models.ValidateModel(req.ModelID, h.defs, h.hw); err != nil {
		writeJSON(w, 404, map[string]string{"error": err.Error()})
		return
	}

	// VRAM gate (pre-download): fetch the GGUF header over HTTP Range and refuse
	// the switch if the model can't fit, so the user never downloads an
	// impossible model. If the header can't be fetched, we log and defer to the
	// post-download backstop in runModelSwitch.
	if _, p, ok := models.ResolveProfile(req.ModelID, h.defs, h.hw); ok {
		kvType := p.KVCacheType
		if kvType == "" {
			kvType = "q8_0"
		}
		ctx := req.CtxSize
		if ctx <= 0 {
			ctx = p.CtxSize
		}
		if err := h.preDownloadGate(req.ModelID, ctx, kvType); err != nil {
			util.LogPrint("[-] model switch to %s refused by VRAM gate: %v\n", req.ModelID, err)
			writeJSON(w, 409, map[string]string{"error": err.Error()})
			return
		}
	}

	name := h.defs[req.ModelID].Name

	h.switchMu.Lock()
	if h.sw.Active {
		busy := h.sw
		h.switchMu.Unlock()
		writeJSON(w, 409, map[string]interface{}{
			"status":   "busy",
			"model_id": busy.ModelID,
			"name":     busy.Name,
			"stage":    busy.Stage,
			"progress": busy.Progress,
		})
		return
	}
	h.sw = switchStatus{
		Active:    true,
		ModelID:   req.ModelID,
		Name:      name,
		Stage:     "resolving",
		Progress:  0,
		StartedAt: time.Now(),
	}
	ctx, cancel := context.WithCancel(context.Background())
	h.switchCancel = cancel
	h.switchMu.Unlock()

	go h.runModelSwitch(ctx, req.ModelID, req.CtxSize)

	writeJSON(w, 202, map[string]interface{}{
		"status":   "switching",
		"model_id": req.ModelID,
		"name":     name,
	})
}

// runModelSwitch performs the download + restart off the HTTP handler's
// goroutine so the client request returns immediately. Progress and the final
// outcome are published via h.sw for /api/switch-status to observe.
func (h *modelsHandler) runModelSwitch(ctx context.Context, modelID string, ctxSize int) {
	resolved, err := models.ResolveModelContext(ctx, modelID, h.defs, h.hw, h.modelDir, func(pct float64) {
		h.switchMu.Lock()
		h.sw.Stage = "downloading"
		h.sw.Progress = pct
		h.switchMu.Unlock()
	})
	if err != nil {
		if ctx.Err() != nil {
			h.finishSwitchError("Model switch cancelled.")
			util.LogPrint("[!] model switch to %s cancelled\n", modelID)
		} else {
			h.finishSwitchError("download/resolve failed: " + err.Error())
			util.LogPrint("[-] model switch to %s failed: %v\n", modelID, err)
		}
		return
	}

	if ctxSize > 0 {
		resolved.CtxSize = ctxSize
	} else {
		// No explicit ctx from the client: clamp the profile default to what
		// actually fits on this GPU, so the default never overflows.
		resolved.CtxSize = fitContext(resolved)
	}

	// VRAM gate (backstop): parse the now-local GGUF and refuse before killing
	// the current server or starting the new one, so an overflow can never be
	// started even if the pre-download header fetch was skipped.
	if header, err := gguf.ParseFile(resolved.ModelPath); err == nil {
		var mmprojBytes uint64
		if resolved.MMProjPath != "" {
			if st, err := os.Stat(resolved.MMProjPath); err == nil {
				mmprojBytes = uint64(st.Size())
			}
		}
		if err := h.vramGate(modelID, resolved.CtxSize, resolved.KVCacheType, header, mmprojBytes); err != nil {
			h.finishSwitchError("refused by VRAM gate: " + err.Error())
			util.LogPrint("[-] model switch to %s refused by VRAM gate: %v\n", modelID, err)
			return
		}
	}

	h.switchMu.Lock()
	h.sw.Stage = "starting"
	h.sw.Progress = 100
	h.sw.CtxSize = resolved.CtxSize
	h.sw.Sampling = resolved.SamplingJSON()
	h.sw.RuntimePolicy = resolved.Runtime.RuntimePolicyJSON()
	h.switchMu.Unlock()

	writeChatTemplate(filepath.Dir(h.modelDir), resolved)

	llama.KillIfRunning(&LlamaProcess)
	LlamaProcess = llama.StartServerWithFallback(h.binDir, resolved)

	if !llama.Healthy() {
		h.finishSwitchError("model failed to start — check VRAM or the logs")
		util.LogPrint("[-] model switch to %s: llama-server did not become healthy\n", modelID)
		return
	}

	h.writeEnvModel(modelID, resolved)

	h.switchMu.Lock()
	h.sw.Active = false
	h.sw.Stage = "loaded"
	h.sw.Progress = 100
	h.sw.CtxSize = resolved.CtxSize
	h.switchCancel = nil
	h.switchMu.Unlock()
	util.LogPrint("[+] model switch complete: %s (ctx: %d)\n", resolved.Name, resolved.CtxSize)
}

func (h *modelsHandler) finishSwitchError(errMsg string) {
	h.switchMu.Lock()
	h.sw.Active = false
	h.sw.Stage = "error"
	h.sw.Error = errMsg
	h.switchCancel = nil
	h.switchMu.Unlock()
}

// preDownloadGate fetches the model's GGUF header over HTTP Range and runs the
// VRAM check before anything is downloaded. A header-fetch failure is NOT a
// refusal — the post-download backstop catches that case after download.
func (h *modelsHandler) preDownloadGate(modelID string, ctxSize int, kvType string) error {
	def := h.defs[modelID]
	headerData, err := gguf.FetchHeader(def.Model.URL)
	if err != nil {
		util.LogPrint("[!] VRAM gate: header fetch failed for %s: %v (deferring to post-download check)\n", modelID, err)
		return nil
	}
	header, err := gguf.Parse(headerData)
	if err != nil {
		util.LogPrint("[!] VRAM gate: header parse failed for %s: %v (deferring to post-download check)\n", modelID, err)
		return nil
	}
	var mmprojBytes uint64
	if def.MMProj != nil && def.MMProj.URL != "" {
		if sz, err := gguf.FetchFileSize(def.MMProj.URL); err == nil {
			mmprojBytes = sz
		}
	}
	return h.vramGate(modelID, ctxSize, kvType, header, mmprojBytes)
}

// vramGate computes the hard VRAM ceiling for a model at a given ctx and returns
// an error if it exceeds the currently-free VRAM. --parallel and --kv-unified
// are set in llama/server.go:StartServer (llama.ParallelSlots) and must stay in sync.
func (h *modelsHandler) vramGate(modelID string, ctxSize int, kvType string, header *gguf.Header, mmprojBytes uint64) error {
	total, err := gpu.TotalVRAMBytes()
	if err != nil {
		return fmt.Errorf("cannot read total VRAM: %w", err)
	}
	opts := memcalc.MemOpts{Ctx: ctxSize, KVType: kvType, Parallel: llama.ParallelSlots, Unified: true}
	required := memcalc.Required(header, mmprojBytes, opts)
	if required <= total {
		return nil
	}
	max := memcalc.MaxContext(header, mmprojBytes, total, opts)
	return fmt.Errorf("%s at ctx %d needs %.2f GiB but the GPU has %.2f GiB total — max ctx for this model is %d",
		h.defs[modelID].Name, ctxSize,
		float64(required)/(1024*1024*1024),
		float64(total)/(1024*1024*1024),
		max)
}

// modelHeader returns the parsed GGUF header (and mmproj size) for a model,
// preferring the local file and falling back to an HTTP Range fetch of just the
// header when the model isn't downloaded yet. Cached per model so the settings
// dropdown doesn't re-fetch on every open.
func (h *modelsHandler) modelHeader(modelID string) (*gguf.Header, uint64) {
	h.headerMu.Lock()
	if h.headerCache == nil {
		h.headerCache = make(map[string]headerCacheEntry)
	}
	if e, ok := h.headerCache[modelID]; ok {
		h.headerMu.Unlock()
		return e.header, e.mmprojBytes
	}
	h.headerMu.Unlock()

	def := h.defs[modelID]
	var header *gguf.Header
	var mmprojBytes uint64

	if hh, err := gguf.ParseFile(filepath.Join(h.modelDir, def.Model.File)); err == nil {
		header = hh
	} else if data, ferr := gguf.FetchHeader(def.Model.URL); ferr == nil {
		if hh, perr := gguf.Parse(data); perr == nil {
			header = hh
		}
	}

	if header != nil {
		if def.MMProj != nil && def.MMProj.URL != "" {
			if st, err := os.Stat(filepath.Join(h.modelDir, def.MMProj.File)); err == nil {
				mmprojBytes = uint64(st.Size())
			} else if sz, err := gguf.FetchFileSize(def.MMProj.URL); err == nil {
				mmprojBytes = sz
			}
		}
	}

	h.headerMu.Lock()
	h.headerCache[modelID] = headerCacheEntry{header: header, mmprojBytes: mmprojBytes}
	h.headerMu.Unlock()
	return header, mmprojBytes
}

// maxContextFor returns the largest context (tokens) that fits on the GPU for a
// profile, or 0 if it can't be computed (no header / no VRAM reading). The
// settings dropdown clamps its offered ctx to this so it never promises a
// window that can't actually be loaded.
func (h *modelsHandler) maxContextFor(modelID string, p models.DeploymentProfile, total uint64) int {
	if total == 0 {
		return 0
	}
	header, mmprojBytes := h.modelHeader(modelID)
	if header == nil {
		return 0
	}
	kvType := p.KVCacheType
	if kvType == "" {
		kvType = "q8_0"
	}
	opts := memcalc.MemOpts{Ctx: p.CtxSize, KVType: kvType, Parallel: llama.ParallelSlots, Unified: true}
	return memcalc.MaxContext(header, mmprojBytes, total, opts)
}

// warmModelHeaders prefetches and caches every model's GGUF header in the
// background so the settings dropdown is fast and honest on first open.
func (h *modelsHandler) warmModelHeaders() {
	for id, def := range h.defs {
		if def.Model.File == "" || def.Model.URL == "" {
			continue
		}
		h.modelHeader(id)
	}
}

// fitContext clamps ctx to the largest value that fits the GPU for a resolved
// model (whose GGUF is already on disk). Returns ctx unchanged if the GGUF or
// VRAM can't be read — the switch gate backstops that case.
func fitContext(m *models.ResolvedModel) int {
	header, err := gguf.ParseFile(m.ModelPath)
	if err != nil {
		return m.CtxSize
	}
	var mmprojBytes uint64
	if m.MMProjPath != "" {
		if st, err := os.Stat(m.MMProjPath); err == nil {
			mmprojBytes = uint64(st.Size())
		}
	}
	total, err := gpu.TotalVRAMBytes()
	if err != nil {
		return m.CtxSize
	}
	kvType := m.KVCacheType
	if kvType == "" {
		kvType = "q8_0"
	}
	opts := memcalc.MemOpts{Ctx: m.CtxSize, KVType: kvType, Parallel: llama.ParallelSlots, Unified: true}
	if max := memcalc.MaxContext(header, mmprojBytes, total, opts); max > 0 && m.CtxSize > max {
		return max
	}
	return m.CtxSize
}

func (h *modelsHandler) handleCancelSwitch(w http.ResponseWriter, _ *http.Request) {
	h.switchMu.Lock()
	cancel := h.switchCancel
	active := h.sw.Active
	h.switchMu.Unlock()

	if cancel != nil && active {
		cancel()
		util.LogPrint("[!] model switch cancellation requested\n")
	}
	writeJSON(w, 200, map[string]string{"status": "cancelling"})
}

func (h *modelsHandler) handleSwitchStatus(w http.ResponseWriter, _ *http.Request) {
	h.switchMu.Lock()
	s := h.sw
	h.switchMu.Unlock()
	writeJSON(w, 200, s)
}

// writeEnvModel persists the resolved model identity into the launcher's own
// .env (used for boot-time model selection). The PHP app has its own .env copy
// that the web layer updates independently.
func (h *modelsHandler) writeEnvModel(modelID string, resolved *models.ResolvedModel) {
	workDir := filepath.Dir(h.modelDir)
	envPath := filepath.Join(workDir, ".env")
	if existing, err := os.ReadFile(envPath); err == nil {
		lines := strings.Split(string(existing), "\n")
		updated := make([]string, 0, len(lines))
		hasModelID, hasModelName, hasCtxSize := false, false, false
		hasSampling, hasPolicy := false, false
		for _, line := range lines {
			trimmed := strings.TrimSpace(line)
			if strings.HasPrefix(trimmed, "LLM_MODEL_ID=") {
				updated = append(updated, "LLM_MODEL_ID="+modelID)
				hasModelID = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_MODEL_NAME=") {
				updated = append(updated, "LLM_MODEL_NAME="+env.QuoteEnvValue(resolved.Name))
				hasModelName = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_CTX_SIZE=") {
				updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(resolved.CtxSize))
				hasCtxSize = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_SAMPLING=") {
				updated = append(updated, "LLM_SAMPLING="+env.QuoteEnvValue(resolved.SamplingJSON()))
				hasSampling = true
				continue
			}
			if strings.HasPrefix(trimmed, "LLM_RUNTIME_POLICY=") {
				updated = append(updated, "LLM_RUNTIME_POLICY="+env.QuoteEnvValue(resolved.Runtime.RuntimePolicyJSON()))
				hasPolicy = true
				continue
			}
			updated = append(updated, line)
		}
		if !hasModelID {
			updated = append(updated, "LLM_MODEL_ID="+modelID)
		}
		if !hasModelName {
			updated = append(updated, "LLM_MODEL_NAME="+env.QuoteEnvValue(resolved.Name))
		}
		if !hasCtxSize && resolved.CtxSize > 0 {
			updated = append(updated, "LLM_CTX_SIZE="+strconv.Itoa(resolved.CtxSize))
		}
		if !hasSampling {
			updated = append(updated, "LLM_SAMPLING="+env.QuoteEnvValue(resolved.SamplingJSON()))
		}
		if !hasPolicy {
			updated = append(updated, "LLM_RUNTIME_POLICY="+env.QuoteEnvValue(resolved.Runtime.RuntimePolicyJSON()))
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
