package launcher

import (
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/getlantern/systray"

	"localsy/internal/docker"
	"localsy/internal/download"
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
	searxngDir := filepath.Join(workDir, "searxng")

	_ = os.MkdirAll(binDir, 0755)
	_ = os.MkdirAll(modelDir, 0755)
	_ = os.MkdirAll(searxngDir, 0755)

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

	tiers := models.LoadConfig(embedded.Models)
	envPath := filepath.Join(workDir, ".env")
	activeTier := models.ResolveActive(envPath, tiers, vram)
	util.LogPrint("[+] Assigned AI Intelligence Tier: %s (%s)\n", activeTier.Name, activeTier.File)

	llama.UpdateServer(binDir, vendor)

	modelPath := filepath.Join(modelDir, activeTier.File)
	if _, err := os.Stat(modelPath); os.IsNotExist(err) {
		util.LogPrint("[+] GGUF Model file is missing. Initializing automated download...\n")
		tmpPath := modelPath + ".tmp"

		err := download.File(tmpPath, activeTier.URL, func(pct float64) {
			systray.SetTooltip(fmt.Sprintf("Localsy: Downloading AI Model (%.1f%%)...", pct))
		})

		if err != nil {
			util.LogPrint("[-] Critical Error downloading model: %v\n", err)
			_ = os.Remove(tmpPath)
			systray.Quit()
			return
		}

		if err = os.Rename(tmpPath, modelPath); err != nil {
			util.LogPrint("[-] Critical Error finalizing model file: %v\n", err)
			systray.Quit()
			return
		}
		util.LogPrint("[+] Model downloaded successfully!\n")
	}

	mmprojPath := ""
	if activeTier.MMProjFile != "" && activeTier.MMProjURL != "" {
		mmprojPath = filepath.Join(modelDir, activeTier.MMProjFile)
		if _, err := os.Stat(mmprojPath); os.IsNotExist(err) {
			util.LogPrint("[+] GGUF Multimodal Projector is missing. Initializing automated download...\n")
			tmpMMPath := mmprojPath + ".tmp"

			err := download.File(tmpMMPath, activeTier.MMProjURL, func(pct float64) {
				systray.SetTooltip(fmt.Sprintf("Localsy: Downloading Vision Module (%.1f%%)...", pct))
			})

			if err != nil {
				util.LogPrint("[-] Critical Error downloading mmproj: %v\n", err)
				_ = os.Remove(tmpMMPath)
				systray.Quit()
				return
			}

			if err = os.Rename(tmpMMPath, mmprojPath); err != nil {
				util.LogPrint("[-] Critical Error finalizing mmproj file: %v\n", err)
				systray.Quit()
				return
			}
			util.LogPrint("[+] Multimodal Projector downloaded successfully!\n")
		}
	}

	systray.SetTooltip("Localsy is running background services")

	docker.EnsureHeadlessReady(binDir, workDir)

	util.WriteConfig(filepath.Join(workDir, "docker-compose.yml"), embedded.Compose)
	util.WriteConfig(filepath.Join(searxngDir, "settings.yml"), embedded.SearXNG)

	registry, ctxSize, useLocal := env.MergeAndWrite(workDir, activeTier.Name, vram, modelPath)

	util.LogPrint("[+] Aligning systemic workspace file rights inside WSL...\n")
	wslWorkDir := util.ToWslPath(workDir)
	_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "chmod", "-R", "777", wslWorkDir).Run()

	DockerProcess = docker.StartHeadlessDaemon()
	util.ApplyWslNatRule()
	docker.StartCompose(workDir, binDir, registry)

	if useLocal {
		LlamaProcess = llama.StartServer(binDir, modelPath, mmprojPath, activeTier.Name, ctxSize)
		llama.WaitForReady()
	}

	util.LogPrint("[+] %s: Startup complete. App reachable at http://localhost:8080\n", time.Now().Format("2006-01-02 15:04:05"))

	if !DebugMode {
		OpenBrowser("http://localhost:8080")
	}
}
