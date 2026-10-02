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

	// An entry carrying an engine spec is served by that engine, not llama.cpp:
	// there is no artifact to download and no llama.cpp command line to build.
	engine := defs[modelID].Engine
	var resolved *models.ResolvedModel
	var err error
	if engine != nil {
		resolved, err = models.ResolveEngineModel(modelID, defs, hw)
		if err != nil {
			util.LogPrint("[-] Critical Error resolving engine model %s: %v\n", modelID, err)
			systray.Quit()
			return
		}
		util.LogPrint("[+] Selected model: %s (ctx: %d, engine: %s on port %d)\n",
			resolved.Name, resolved.CtxSize, engine.Type, engine.Port)
	} else {
		resolved, err = resolveModel(modelID)
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

		// Cold-hard VRAM check: clamp the boot ctx to the largest that fits on this
		// GPU (from the just-downloaded GGUF) so startup can never overflow.
		if clamped := fitContext(resolved); clamped != resolved.CtxSize {
			util.LogPrint("[!] boot ctx clamped to fit VRAM: %d -> %d\n", resolved.CtxSize, clamped)
			resolved.CtxSize = clamped
		}

		util.LogPrint("[+] Selected model: %s (ctx: %d)\n", resolved.Name, resolved.CtxSize)
	}

	systray.SetTooltip("Localsy is running background services")

	llama.UpdateServer(binDir, vendor)

	docker.EnsureHeadlessReady(binDir, workDir)

	util.WriteConfig(filepath.Join(workDir, "docker-compose.yml"), embedded.Compose)

	registry, ctxSize, useLocal := env.MergeAndWrite(
		workDir,
		modelID,
		resolved.Name,
		resolved.CtxSize,
		resolved.SamplingJSON(),
		resolved.Runtime.RuntimePolicyJSON(),
		llmAPIURL(engine),
	)

	relay := bridge.NewRelay()
	go func() {
		mux := http.NewServeMux()
		mux.HandleFunc("/", relay.ServeWS)
		if err := http.ListenAndServe(":8765", mux); err != nil {
			util.LogPrint("Bridge WS server error: %v\n", err)
		}
	}()

	// Publish the boot-resolved sampling + reasoning policy to /api/switch-status. The web
	// layer reads them to resolve the Reasoning control's graduated levels on a cold start;
	// a switch publishes its own values and clears these. Set before the server starts.
	BootSampling = resolved.SamplingJSON()
	BootRuntimePolicy = resolved.Runtime.RuntimePolicyJSON()

	StartHTTPServer(defs, hw, binDir, modelDir, relay)

	util.LogPrint("[+] Aligning systemic workspace file rights inside WSL...\n")
	wslWorkDir := util.ToWslPath(workDir)
	_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "chmod", "-R", "777", wslWorkDir).Run()

	DockerProcess = docker.StartHeadlessDaemon()
	util.ApplyWslNatRule()
	docker.StartCompose(workDir, binDir, registry)

	// A launcher that was restarted while an external engine was running leaves
	// the engine's tree behind (it survives its parent). Reclaim the card before
	// starting anything.
	stopOwnedRuntime(workDir, StrataProcess)
	StrataProcess = nil

	if useLocal {
		if engine != nil {
			cmd := startStrata(engine, workDir)
			switch {
			case cmd == nil:
				reportEngineFailure(recordEngineStartError(
					fmt.Sprintf("The %s AI engine could not be started.", engine.Type),
					"the launcher could not spawn the engine process — see the launcher log for the reason",
				))
			default:
				StrataProcess = cmd
				if !waitStrataReady(engine.Port, uint32(cmd.Process.Pid)) {
					reportEngineFailure(recordEngineStartError(
						fmt.Sprintf("The %s AI engine started but never reported the model loaded.", engine.Type),
						fmt.Sprintf("no ready answer on port %d within the boot wait — check the engine log", engine.Port),
					))
				} else {
					clearEngineStartError()
				}
			}
		} else {
			writeChatTemplate(workDir, resolved)
			LlamaProcess = llama.StartServerWithFallback(binDir, resolved)
		}
	}

	_ = ctxSize

	util.LogPrint("[+] %s: Startup complete. App reachable at http://localhost:8080\n", time.Now().Format("2006-01-02 15:04:05"))

	if !DebugMode {
		OpenBrowser("http://localhost:8080")
	}
}

// reportEngineFailure makes a boot-time engine failure visible. The log line stays
// (for the diagnostics bundle), the tray tooltip says what happened, and a message
// box tells the user once — spawned on its own goroutine so the boot sequence still
// finishes and the browser still opens. Deliberately NOT systray.Quit(): the UI
// stays usable (files, jobs, settings) and the user can retry a switch.
func reportEngineFailure(f *EngineStartError) {
	if f == nil {
		return
	}
	util.LogPrint("[-] %s %s\n", f.Message, f.Detail)
	systray.SetTooltip("Localsy: " + f.Message)
	go ShowErrorMessageBox(
		"Localsy - AI engine not running",
		f.Message+"\n\n"+f.Detail+"\n\nThe app is still usable and you can retry from Settings -> AI.",
	)
}

// llmAPIURL is the endpoint the web layer must talk to for a given runtime: the
// external engine's own port when one is configured, otherwise "" (llama-server
// on :1234, the default the env merge writes itself).
func llmAPIURL(engine *models.EngineSpec) string {
	if engine == nil {
		return ""
	}
	return engine.APIURL(util.GetWindowsHostIP())
}

// writeChatTemplate materializes the embedded chat template (when the resolved
// runtime requests one) to disk and rewrites m.Runtime.TemplateFile to the
// absolute path llama-server should load. On failure it clears the field so
// StartServer falls back to the GGUF-embedded template.
func writeChatTemplate(workDir string, m *models.ResolvedModel) {
	if m.Runtime.TemplateFile == "" || len(embedded.Template) == 0 {
		return
	}
	path := filepath.Join(workDir, m.Runtime.TemplateFile)
	if err := os.WriteFile(path, embedded.Template, 0644); err != nil {
		util.LogPrint("[!] chat template %s write failed: %v — using GGUF-embedded template\n", path, err)
		m.Runtime.TemplateFile = ""
		return
	}
	m.Runtime.TemplateFile = path
}
