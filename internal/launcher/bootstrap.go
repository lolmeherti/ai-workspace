package launcher

import (
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"github.com/getlantern/systray"

	"localsy/internal/docker"
	"localsy/internal/bridge"
	"localsy/internal/env"
	"localsy/internal/gpu"
	"localsy/internal/llama"
	"localsy/internal/models"
	"localsy/internal/util"
)

func Bootstrap() {
	appData := os.Getenv("LOCALAPPDATA")
	workDir := filepath.Join(appData, "localsy")
	binDir := filepath.Join(workDir, "bin")
	modelDir := filepath.Join(workDir, "models")

	_ = os.MkdirAll(binDir, 0755)
	_ = os.MkdirAll(modelDir, 0755)

	excludedMarker := filepath.Join(workDir, ".excluded")
	serverExe := filepath.Join(binDir, "llama-server.exe")

	if _, err := os.Stat(excludedMarker); os.IsNotExist(err) {
		if _, statErr := os.Stat(serverExe); os.IsNotExist(statErr) {
			ShowErrorMessageBox(
				"Localsy - Protection Warning",
				"CRITICAL ERROR: llama-server.exe is missing from your directory.\n\n"+
					"Windows Defender has blocked or quarantined the AI GPU engine.\n\n"+
					"Please run 'setup-exclusions.bat' as Administrator, then restart this application.",
			)
			systray.Quit()
			return
		}
	}

	InitLogging(workDir)
	util.LogPrint("[+] %s: Initializing Localsy Environment...\n", time.Now().Format("2006-01-02 15:04:05"))

	vendor, vram := gpu.Detect()
	util.LogPrint("[+] Detected GPU: %s with %.2f GB VRAM\n", vendor, vram)

	defs := models.LoadConfig(embedded.Models)
	hw := models.Hardware{VRAMGB: vram}

	envPath := filepath.Join(workDir, ".env")
	modelID := models.ResolveActiveModelID(envPath, defs)
	if modelID == "" {
		modelID = models.AutoSelectModelID(defs, hw)
	}

	resolveModel := func(id string) (*models.ResolvedModel, error) {
		return models.ResolveModel(id, defs, hw, modelDir, func(pct float64) {
			systray.SetTooltip(fmt.Sprintf("Localsy: Downloading AI Model (%.1f%%)...", pct))
		})
	}

	resolved, err := resolveModel(modelID)
	if err != nil {
		util.LogPrint("[!] persisted model %q failed to resolve: %v\n", modelID, err)
		fallbackID := models.AutoSelectModelID(defs, hw)
		if fallbackID == "" || fallbackID == modelID {
			util.LogPrint("[-] Critical Error: no usable model found\n")
			systray.Quit()
			return
		}
		util.LogPrint("[+] falling back to auto-selected model: %s\n", fallbackID)
		resolved, err = resolveModel(fallbackID)
		if err != nil {
			util.LogPrint("[-] Critical Error resolving fallback model: %v\n", err)
			systray.Quit()
			return
		}
		modelID = fallbackID
	}

	util.LogPrint("[+] Selected model: %s (ctx: %d)\n", resolved.Name, resolved.CtxSize)

	systray.SetTooltip("Localsy is running background services")

	llama.UpdateServer(binDir, vendor)

	docker.EnsureHeadlessReady(binDir, workDir)

	util.WriteConfig(filepath.Join(workDir, "docker-compose.yml"), embedded.Compose)

	registry, ctxSize, useLocal := env.MergeAndWrite(workDir, modelID, resolved.Name, resolved.CtxSize)

	relay := bridge.NewRelay()
	go func() {
		mux := http.NewServeMux()
		mux.HandleFunc("/", relay.ServeWS)
		if err := http.ListenAndServe(":8765", mux); err != nil {
			util.LogPrint("Bridge WS server error: %v\n", err)
		}
	}()

	StartHTTPServer(defs, hw, binDir, modelDir, relay)

	util.LogPrint("[+] Aligning systemic workspace file rights inside WSL...\n")
	wslWorkDir := util.ToWslPath(workDir)
	_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "chmod", "-R", "777", wslWorkDir).Run()

	DockerProcess = docker.StartHeadlessDaemon()
	util.ApplyWslNatRule()
	docker.StartCompose(workDir, binDir, registry)

	if useLocal {
		LlamaProcess = llama.StartServerWithFallback(binDir, resolved)
	}

	_ = ctxSize

	util.LogPrint("[+] %s: Startup complete. App reachable at http://localhost:8080\n", time.Now().Format("2006-01-02 15:04:05"))

	if !DebugMode {
		OpenBrowser("http://localhost:8080")
	}
}
