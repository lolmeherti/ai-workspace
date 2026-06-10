package main

import (
	"archive/zip"
	"bytes"
	_ "embed"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"

	"github.com/getlantern/systray"
)

// Structural mapping for the models.json tiers
type ModelTier struct {
	Name       string `json:"name"`
	File       string `json:"file"`
	URL        string `json:"url"`
	MMProjFile string `json:"mmproj_file,omitempty"` // Added for vision support
	MMProjURL  string `json:"mmproj_url,omitempty"`  // Added for vision support
}

// GitHub API Releases payload structure
type GHRelease struct {
	TagName string `json:"tag_name"`
	Assets  []struct {
		Name        string `json:"name"`
		DownloadURL string `json:"browser_download_url"`
	} `json:"assets"`
}

// -------------------------------------------------------------
// NATIVE GO EMBED FILES
// -------------------------------------------------------------

//go:embed docker-compose.prod.yml
var embeddedCompose []byte

//go:embed models.json
var embeddedModels []byte

//go:embed searxng/settings.yml
var embeddedSearXNG []byte

//go:embed icon.ico
var iconData []byte

// Global execution tracking variables
var logWriter io.Writer = os.Stdout
var logFile *os.File
var dockerProcess *exec.Cmd
var llamaProcess *exec.Cmd
var debugMode *bool

// Package-level logging helper
func logPrint(format string, a ...interface{}) {
	fmt.Fprintf(logWriter, format, a...)
}

func main() {
	// Parse CLI flags (e.g., localsy.exe -debug)
	debugMode = flag.Bool("debug", false, "Enable verbose logging and spawn a live log terminal")
	flag.Parse()

	// 1. SELF-HEALING: Clean up duplicate instances on start
	killDuplicateLauncherInstances()
	killOrphanedProcesses()

	// 2. Initialize the Windows System Tray Lifecycle
	systray.Run(onReady, onExit)
}

// Spawns native Windows dialog prompts for critical errors since the console is hidden
func showErrorMessageBox(title, text string) {
	user32 := syscall.NewLazyDLL("user32.dll")
	messageBox := user32.NewProc("MessageBoxW")
	_, _, _ = messageBox.Call(
		0,
		uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr(text))),
		uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr(title))),
		0x00000010, // MB_OK | MB_ICONERROR
	)
}

func onReady() {
	// Configure taskbar visual properties
	systray.SetIcon(iconData)
	systray.SetTitle("Localsy AI Engine")
	systray.SetTooltip("Localsy is running background services")

	// Set up right-click menus
	mOpen := systray.AddMenuItem("Open Web Interface", "Launch browser to Localsy")
	mLogs := systray.AddMenuItem("View Live Logs", "Open scrolling logs window")
	systray.AddSeparator()
	mExit := systray.AddMenuItem("Shut Down", "Stop all background engines and exit")

	// Start the main bootstrap logic on an independent thread so it doesn't freeze the taskbar
	go bootstrapLocalsy()

	// Handle menu action clicks
	go func() {
		for {
			select {
			case <-mOpen.ClickedCh:
				openBrowser("http://localhost:8080")
			case <-mLogs.ClickedCh:
				appData := os.Getenv("LOCALAPPDATA")
				logFilePath := filepath.Join(appData, "localsy", "localsy.log")
				spawnLogTerminal(logFilePath)
			case <-mExit.ClickedCh:
				systray.Quit()
			}
		}
	}()
}

func openBrowser(url string) {
	cmd := runSilentCommand("cmd", "/c", "start", url)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Run()
}

func bootstrapLocalsy() {
	// Establish AppData Working Directories
	appData := os.Getenv("LOCALAPPDATA")
	workDir := filepath.Join(appData, "localsy")
	binDir := filepath.Join(workDir, "bin")
	modelDir := filepath.Join(workDir, "models")
	searxngDir := filepath.Join(workDir, "searxng")

	_ = os.MkdirAll(binDir, 0755)
	_ = os.MkdirAll(modelDir, 0755)
	_ = os.MkdirAll(searxngDir, 0755)

	// --- Windows Defender Safety Check ---
	excludedMarker := filepath.Join(workDir, ".excluded")
	serverExe := filepath.Join(binDir, "llama-server.exe")

	if _, err := os.Stat(excludedMarker); os.IsNotExist(err) {
		if _, statErr := os.Stat(serverExe); os.IsNotExist(statErr) {
			showErrorMessageBox(
				"Localsy - Protection Warning",
				"CRITICAL ERROR: llama-server.exe is missing from your directory.\n\n"+
					"Windows Defender has blocked or quarantined the AI GPU engine.\n\n"+
					"Please run 'setup-exclusions.bat' as Administrator, then restart this application.",
			)
			systray.Quit()
			return
		}
	}

	// Initialize log file
	logFilePath := filepath.Join(workDir, "localsy.log")
	var err error
	logFile, err = os.OpenFile(logFilePath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
	if err == nil {
		logWriter = io.MultiWriter(os.Stdout, logFile)
		
		// Only spawn scrolling logs window if launcher was invoked with the -debug flag
		if *debugMode {
			spawnLogTerminal(logFilePath)
		}
	}

	logPrint("[+] %s: Initializing Localsy Environment...\n", time.Now().Format("2006-01-02 15:04:05"))

	// Hardware Detection
	vendor, vram := detectGPU()
	logPrint("[+] Detected GPU: %s with %.2f GB VRAM\n", vendor, vram)

	// Load Embedded Model Tiers
	tiers := loadModelsConfig()

	// Resolve Active Model Tier
	envPath := filepath.Join(workDir, ".env")
	activeTier := resolveActiveModel(envPath, tiers, vram)
	logPrint("[+] Assigned AI Intelligence Tier: %s (%s)\n", activeTier.Name, activeTier.File)

	// Update and Download llama-server Binary
	updateLlamaServer(binDir, vendor)

	// Ensure the Model File Exists
	modelPath := filepath.Join(modelDir, activeTier.File)
	if _, err := os.Stat(modelPath); os.IsNotExist(err) {
		logPrint("[+] GGUF Model file is missing. Initializing automated download...\n")
		tmpPath := modelPath + ".tmp"
		
		// Download with live tray status updates
		err := downloadFile(tmpPath, activeTier.URL, func(pct float64) {
			systray.SetTooltip(fmt.Sprintf("Localsy: Downloading AI Model (%.1f%%)...", pct))
		})
		
		if err != nil {
			logPrint("[-] Critical Error downloading model: %v\n", err)
			_ = os.Remove(tmpPath)
			systray.Quit()
			return
		}

		err = os.Rename(tmpPath, modelPath)
		if err != nil {
			logPrint("[-] Critical Error finalizing model file: %v\n", err)
			systray.Quit()
			return
		}
		logPrint("[+] Model downloaded successfully!\n")
	}

	// Ensure the Multimodal Projector (mmproj) File Exists
	mmprojPath := ""
	if activeTier.MMProjFile != "" && activeTier.MMProjURL != "" {
		mmprojPath = filepath.Join(modelDir, activeTier.MMProjFile)
		if _, err := os.Stat(mmprojPath); os.IsNotExist(err) {
			logPrint("[+] GGUF Multimodal Projector is missing. Initializing automated download...\n")
			tmpMMPath := mmprojPath + ".tmp"
			
			// Download with live tray status updates
			err := downloadFile(tmpMMPath, activeTier.MMProjURL, func(pct float64) {
				systray.SetTooltip(fmt.Sprintf("Localsy: Downloading Vision Module (%.1f%%)...", pct))
			})
			
			if err != nil {
				logPrint("[-] Critical Error downloading mmproj: %v\n", err)
				_ = os.Remove(tmpMMPath)
				systray.Quit()
				return
			}

			err = os.Rename(tmpMMPath, mmprojPath)
			if err != nil {
				logPrint("[-] Critical Error finalizing mmproj file: %v\n", err)
				systray.Quit()
				return
			}
			logPrint("[+] Multimodal Projector downloaded successfully!\n")
		}
	}

	// Reset tooltip back to standard running state once downloads complete
	systray.SetTooltip("Localsy is running background services")

	// Ensure Headless Docker Engine is Installed
	ensureHeadlessDockerReady(binDir, workDir)

	// Write Configurations and Merge .env
	writeConfig(filepath.Join(workDir, "docker-compose.yml"), embeddedCompose)
	writeConfig(filepath.Join(searxngDir, "settings.yml"), embeddedSearXNG)
    
	registry, ctxSize, useLocal := mergeAndWriteEnvFile(workDir, activeTier.Name, vram, modelPath)

	// Align system permissions
	logPrint("[+] Aligning systemic workspace file rights inside WSL...\n")
	wslWorkDir := toWslPath(workDir)
	_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "chmod", "-R", "777", wslWorkDir).Run()

	// Start Headless Docker Engine
	dockerProcess = startHeadlessDockerDaemon()

	// Apply WSL NAT rule
	applyWslNatRule()

	// Orchestrate Docker Compose
	startDockerCompose(workDir, binDir, registry)

	// Start Background llama-server with Multimodal Projector
	if useLocal {
		llamaProcess = startLlamaServer(binDir, modelPath, mmprojPath, activeTier.Name, ctxSize)
		waitForLlamaServerReady()
	}

	logPrint("[+] %s: Startup complete. App reachable at http://localhost:8080\n", time.Now().Format("2006-01-02 15:04:05"))
	
	// Automatically launch browser for end users on successful boot (unless in debug mode)
	if !*debugMode {
		openBrowser("http://localhost:8080")
	}
}

func onExit() {
	logPrint("[!] %s: Shut Down initiated. Cleaning up background services...\n", time.Now().Format("2006-01-02 15:04:05"))

	// Gracefully close llama-server
	if llamaProcess != nil && llamaProcess.Process != nil {
		done := make(chan error, 1)
		go func() {
			done <- llamaProcess.Wait()
		}()

		select {
		case <-done:
			logPrint("[+] AI GPU engine shut down cleanly.\n")
		case <-time.After(3 * time.Second):
			_ = llamaProcess.Process.Kill()
		}
	}

	// Gracefully shutdown custom WSL instances to release disk locks
	cmdWsl := runSilentCommand("wsl", "--terminate", "localsy-docker-backend")
	cmdWsl.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdWsl.Run()

	if logFile != nil {
		_ = logFile.Close()
	}
}

// -------------------------------------------------------------
// CORE LAUNCHER SUB-FUNCTIONS
// -------------------------------------------------------------

func getWindowsHostIP() string {
	cmd := runSilentCommand("wsl", "-d", "localsy-docker-backend", "ip", "route")
	var out bytes.Buffer
	cmd.Stdout = &out
	err := cmd.Run()
	
	if err == nil {
		lines := strings.Split(out.String(), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "default via") {
				fields := strings.Fields(line)
				if len(fields) >= 3 {
					return fields[2]
				}
			}
		}
	}

	cmdFallback := runSilentCommand("wsl", "-d", "localsy-docker-backend", "cat", "/etc/resolv.conf")
	var outFallback bytes.Buffer
	cmdFallback.Stdout = &outFallback
	_ = cmdFallback.Run()
	
	lines := strings.Split(outFallback.String(), "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "nameserver") {
			fields := strings.Fields(line)
			if len(fields) >= 2 {
				return fields[1] 
			}
		}
	}

	return "host.docker.internal"
}

func applyWslNatRule() {
	_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "iptables", "-t", "nat", "-D", "POSTROUTING", "-o", "eth0", "-j", "MASQUERADE").Run()
	_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "iptables", "-t", "nat", "-A", "POSTROUTING", "-o", "eth0", "-j", "MASQUERADE").Run()
}

func waitForLlamaServerReady() {
	client := http.Client{Timeout: 1 * time.Second}
	type LlamaHealth struct{ Status string `json:"status"` }
	
	for i := 0; i < 180; i++ {
		resp, err := client.Get("http://127.0.0.1:1234/health")
		if err == nil {
			var h LlamaHealth
			errDec := json.NewDecoder(resp.Body).Decode(&h)
			_ = resp.Body.Close()
			if errDec == nil && h.Status == "ok" {
				return
			}
		}
		time.Sleep(1 * time.Second)
	}
}

func resolveActiveModel(envPath string, tiers map[string]ModelTier, vram float64) ModelTier {
	existing, err := os.ReadFile(envPath)
	if err == nil {
		lines := strings.Split(string(existing), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "LLM_MODEL_NAME=") {
				parts := strings.SplitN(line, "=", 2)
				if len(parts) == 2 {
					configuredModel := strings.TrimSpace(parts[1])
					for _, tier := range tiers {
						if tier.Name == configuredModel {
							return tier
						}
					}
				}
			}
		}
	}

	if vram >= 28.0 {        // Tier 1: Safe margin for 32GB Cards (reports ~31.8 GB)
		return tiers["Tier1"] 
	} else if vram >= 20.0 { // Tier 2: Safe margin for 24GB Cards (reports ~23.8 GB)
		return tiers["Tier2"] 
	} else if vram >= 14.0 { // Tier 3: Safe margin for 16GB Cards (reports ~15.8 GB)
		return tiers["Tier3"] 
	} else if vram >= 10.0 { // Tier 4: Safe margin for 12GB Cards (reports ~11.8 GB)
		return tiers["Tier4"] 
	} else {
		return tiers["Tier5"] // Standard GPUs / CPU fallback (< 10GB)
	}
}

func spawnLogTerminal(logFilePath string) {
	cmd := runSilentCommand("cmd", "/c", "start", "powershell", "-NoExit", "-Command", fmt.Sprintf("Get-Content -Path '%s' -Wait", logFilePath))
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Start()
}

func killDuplicateLauncherInstances() {
	myPid := os.Getpid()
	cmd := runSilentCommand("powershell", "-Command",
		fmt.Sprintf(`Get-Process -Name "localsy" -ErrorAction SilentlyContinue | Where-Object { $_.Id -ne %d } | Stop-Process -Force`, myPid))
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Run()
}

func killOrphanedProcesses() {
	cmdTaskkill := runSilentCommand("taskkill", "/F", "/IM", "llama-server.exe")
	cmdTaskkill.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdTaskkill.Run()

	cmdWsl := runSilentCommand("wsl", "--terminate", "localsy-docker-backend")
	cmdWsl.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdWsl.Run()
}

func loadModelsConfig() map[string]ModelTier {
	var Tiers map[string]ModelTier
	_ = json.Unmarshal(embeddedModels, &Tiers)
	return Tiers
}

func detectGPU() (string, float64) {
	cmd := runSilentCommand("powershell", "-Command",
		`Get-CimInstance -ClassName Win32_VideoController -ErrorAction SilentlyContinue | ForEach-Object { `+
			`$c = $_; `+
			`$id = if ($c.PNPDeviceID) { $c.PNPDeviceID.Split('&')[0..1] -join '&' } else { "" }; `+
			`$reg = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Class\{4d36e968-e325-11ce-bfc1-08002be10318}\0*' -ErrorAction SilentlyContinue | Where-Object { $_.MatchingDeviceId -like "*$id*" } | Select-Object -First 1; `+
			`$ram = if ($reg -and $reg.'HardwareInformation.qwMemorySize') { $reg.'HardwareInformation.qwMemorySize' } else { $c.AdapterRAM }; `+
			`[PSCustomObject]@{ Name = $c.Name; AdapterRAM = $ram } `+
		`} | ConvertTo-Json`)
	
	var out bytes.Buffer
	cmd.Stdout = &out
	_ = cmd.Run()

	type GPUInfo struct {
		Name       string `json:"Name"`
		AdapterRAM int64  `json:"AdapterRAM"`
	}

	var gpus []GPUInfo
	raw := out.Bytes()
	if err := json.Unmarshal(raw, &gpus); err != nil {
		var single GPUInfo
		if err := json.Unmarshal(raw, &single); err == nil {
			gpus = append(gpus, single)
		}
	}

	vendor := "CPU"
	var maxVram int64 = 0

	for _, gpu := range gpus {
		nameLower := strings.ToLower(gpu.Name)
		if strings.Contains(nameLower, "nvidia") || strings.Contains(nameLower, "rtx") || strings.Contains(nameLower, "geforce") {
			vendor = "NVIDIA"
			if gpu.AdapterRAM > maxVram {
				maxVram = gpu.AdapterRAM
			}
		} else if strings.Contains(nameLower, "amd") || strings.Contains(nameLower, "radeon") {
			if vendor != "NVIDIA" {
				vendor = "AMD"
				if gpu.AdapterRAM > maxVram {
					maxVram = gpu.AdapterRAM
				}
			}
		}
	}

	vramGB := float64(maxVram) / (1024 * 1024 * 1024)
	return vendor, vramGB
}

func toWslPath(winPath string) string {
	cleaned := filepath.Clean(winPath)
	vol := filepath.VolumeName(cleaned)
	if vol == "" {
		return winPath
	}
	pathWithoutVol := strings.TrimPrefix(cleaned, vol)
	driveLetter := strings.ToLower(strings.TrimSuffix(vol, ":"))
	return "/mnt/" + driveLetter + filepath.ToSlash(pathWithoutVol)
}

func updateLlamaServer(binDir string, vendor string) {
	resp, err := http.Get("https://api.github.com/repos/ggml-org/llama.cpp/releases/latest")
	if err != nil {
		return
	}
	defer resp.Body.Close()

	var release GHRelease
	_ = json.NewDecoder(resp.Body).Decode(&release)

	serverExe := filepath.Join(binDir, "llama-server.exe")
	versionFile := filepath.Join(binDir, "version.txt")
	localVer, _ := os.ReadFile(versionFile)
	
	var exeErr error
	_, exeErr = os.Stat(serverExe)

	if string(localVer) == release.TagName && exeErr == nil {
		return
	}

	var mainDownloadURL string
	var cudartDownloadURL string

	mainPattern := "llama-" + release.TagName + "-bin-win-x64.zip"
	if vendor == "NVIDIA" {
		mainPattern = "llama-" + release.TagName + "-bin-win-cuda-12.4-x64.zip"
	} else if vendor == "AMD" {
		mainPattern = "llama-" + release.TagName + "-bin-win-vulkan-x64.zip"
	}

	for _, asset := range release.Assets {
		if strings.Contains(asset.Name, mainPattern) {
			mainDownloadURL = asset.DownloadURL
		}
		if vendor == "NVIDIA" && strings.Contains(asset.Name, "cudart-llama-bin-win-cuda-12.4-x64.zip") {
			cudartDownloadURL = asset.DownloadURL
		}
	}

	if mainDownloadURL == "" {
		return
	}

	mainZipPath := filepath.Join(binDir, "update_main.zip")
	if err := downloadFile(mainZipPath, mainDownloadURL, nil); err != nil {
		return
	}
	defer os.Remove(mainZipPath)

	_ = unzip(mainZipPath, binDir)

	if vendor == "NVIDIA" && cudartDownloadURL != "" {
		cudartZipPath := filepath.Join(binDir, "update_cudart.zip")
		if err := downloadFile(cudartZipPath, cudartDownloadURL, nil); err == nil {
			_ = unzip(cudartZipPath, binDir)
			os.Remove(cudartZipPath)
		}
	}

	flattenBinDir(binDir)

	if _, err := os.Stat(serverExe); err == nil {
		_ = os.WriteFile(versionFile, []byte(release.TagName), 0644)
	}
}

func flattenBinDir(binDir string) {
	var foundPath string
	_ = filepath.Walk(binDir, func(path string, info os.FileInfo, err error) error {
		if err == nil && !info.IsDir() && info.Name() == "llama-server.exe" {
			foundPath = path
			return filepath.SkipDir
		}
		return nil
	})

	if foundPath != "" && foundPath != filepath.Join(binDir, "llama-server.exe") {
		_ = os.Rename(foundPath, filepath.Join(binDir, "llama-server.exe"))
		
		srcDir := filepath.Dir(foundPath)
		files, _ := os.ReadDir(srcDir)
		for _, f := range files {
			if strings.HasSuffix(strings.ToLower(f.Name()), ".dll") {
				_ = os.Rename(filepath.Join(srcDir, f.Name()), filepath.Join(binDir, f.Name()))
			}
		}
		_ = os.RemoveAll(srcDir)
	}
}

func ensureHeadlessDockerReady(binDir, workDir string) {
	distroRegistered := isDistroRegistered("localsy-docker-backend")
	wslDir := filepath.Join(workDir, "wsl")
	vhdxPath := filepath.Join(wslDir, "ext4.vhdx")
	_, vhdxErr := os.Stat(vhdxPath)

	if distroRegistered && os.IsNotExist(vhdxErr) {
		_ = runSilentCommand("wsl", "--unregister", "localsy-docker-backend").Run()
		distroRegistered = false
	}

	if !distroRegistered {
		rootfsPath := filepath.Join(workDir, "alpine-rootfs.tar.gz")
		if _, err := os.Stat(rootfsPath); os.IsNotExist(err) {
			_ = downloadFile(rootfsPath, "https://dl-cdn.alpinelinux.org/alpine/v3.21/releases/x86_64/alpine-minirootfs-3.21.0-x86_64.tar.gz", nil)
		}

		_ = os.MkdirAll(wslDir, 0755)
		_ = runSilentCommand("wsl", "--import", "localsy-docker-backend", wslDir, rootfsPath, "--version", "2").Run()
		_ = os.Remove(rootfsPath)

		_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "sh", "-c", "sed -i 's/#http/http/g' /etc/apk/repositories").Run()
		_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "apk", "update").Run()
		_ = runSilentCommand("wsl", "-d", "localsy-docker-backend", "apk", "add", "--no-cache", "docker", "docker-cli-compose", "iptables", "ip6tables").Run()
	}
}

func isDistroRegistered(distroName string) bool {
	cmd := runSilentCommand("wsl", "--list", "--quiet")
	var out bytes.Buffer
	cmd.Stdout = &out
	_ = cmd.Run()

	output := strings.ReplaceAll(out.String(), "\x00", "")
	return strings.Contains(output, distroName)
}

func startHeadlessDockerDaemon() *exec.Cmd {
	cmd := runSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "dockerd", 
		"--host=unix:///var/run/docker.sock", 
		"--host=tcp://127.0.0.1:2375", 
		"--iptables=false")
		
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	_ = cmd.Start()
	
	client := http.Client{Timeout: 1 * time.Second}
	for i := 0; i < 30; i++ {
		resp, err := client.Get("http://127.0.0.1:2375/_ping")
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return cmd
			}
		}
		time.Sleep(1 * time.Second)
	}
	return cmd
}

func writeConfig(dest string, content []byte) {
	_ = os.WriteFile(dest, content, 0644)
}

func mergeAndWriteEnvFile(workDir string, activeModel string, vram float64, modelPath string) (string, int, bool) {
	envPath := filepath.Join(workDir, ".env")
	hostIP := getWindowsHostIP()

	defaults := map[string]string{
		"DOCKER_REGISTRY":                    "deampuleadd",
		"USE_LOCAL_LLM":                      "true",
		"MAX_SCRAPE_TOKENS":                  "2500",
		"MEMORY_EXTRACTION_THRESHOLD_TOKENS": "63141",
		"CHAT_ROLLING_WINDOW_LIMIT":          "15",
		"CONDENSATION_KEEP_LIMIT":            "4",
		"LLM_API_URL":                        "http://host.docker.internal:1234/v1",
		"LLM_MODEL_NAME":                     activeModel,
		"MAX_MEMORIES_LIMIT":                 "500",
		"DEFAULT_CHAT_TEMP":                  "1",
		"AGENT_DECIDER_TEMP":                 "0.3",
		"AGENT_MEMORY_SELECTOR_TEMP":         "0.5",
		"AGENT_CONDENSER_TEMP":               "0.5",
		"AGENT_EXTRACTOR_TEMP":               "1",
		"MAX_SEARCH_RESULTS_TO_SCRAPE":       "3",
		"DB_NAME":                            "ai_memories",
		"DB_USER":                            "ai_user",
		"DB_PASS":                            "secret123",
		"MYSQL_ROOT_PASSWORD":                "rootsecret",
		"DB_HOST":                            "mysql",
		"REDIS_HOST":                         "redis",
		"SEARXNG_HOST":                       "http://searxng:8080",
		"FLARESOLVERR_HOST":                  "http://flaresolverr:8191",
		"TZ":                                 "Europe/Vienna",
		"LOG_LEVEL":                          "info",
		"TODOIST_API_KEY":                    "#PLACEHOLDER#",
		"CALENDAR_FETCH_LIMIT":               "5",
	}

	// 1. Read existing config and capture the currently saved model
	existingModel := ""
	existing, err := os.ReadFile(envPath)
	if err == nil {
		lines := strings.Split(string(existing), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				key := strings.TrimSpace(parts[0])
				value := strings.TrimSpace(parts[1])
				defaults[key] = value
				
				// Capture the model name from the last boot
				if key == "LLM_MODEL_NAME" {
					existingModel = value
				}
			}
		}
	}

	useLocal := strings.ToLower(strings.TrimSpace(defaults["USE_LOCAL_LLM"])) == "true"

	// 2. Parse the context size from .env if it exists
	var ctxSize int
	if val, ok := defaults["LLM_CTX_SIZE"]; ok {
		if ctx, err := strconv.Atoi(strings.TrimSpace(val)); err == nil && ctx > 0 {
			ctxSize = ctx
		}
	}

	// 3. MODEL DRIFT DETECTION:
	// If the model changed (due to hardware swap, update, or manual model toggle), 
	// we force-reset the token window to 0 so the correct hardcoded hardware default is applied.
	if existingModel != activeModel {
		ctxSize = 0
	}

	if useLocal {
		// 4. Fallback to optimal defaults ONLY if the user hasn't explicitly customized LLM_CTX_SIZE
		if ctxSize == 0 {
			switch activeModel {
			case "gemma-4-26B-A4B-it-qat":
				if vram >= 28.0 {        // Match Tier 1 VRAM check
					ctxSize = 160000 // Tier 1: 5090 (Extreme context MoE)
				} else {
					ctxSize = 60000  // Tier 2: 24GB (Deep context MoE)
				}
			case "gemma-4-E4B-it-Q8":
				ctxSize = 65000 // Tier 3: 16GB (E4B Q8)
			case "gemma-4-E4B-it":
				ctxSize = 50000 // Tier 4: 12GB (E4B Q4)
			case "gemma-4-E2B-it":
				ctxSize = 45000 // Tier 5: <=8GB (E2B Q4)
			default:
				ctxSize = 8192  // Safe fallback
			}
		}
		
		defaults["LLM_API_URL"] = fmt.Sprintf("http://%s:1234/v1", hostIP)
		defaults["LLM_MODEL_NAME"] = activeModel
		defaults["LLM_CTX_SIZE"] = strconv.Itoa(ctxSize)
	}

	var buf bytes.Buffer
	buf.WriteString("# Generated dynamically by AIMemoriesLauncher\n\n")

	keysOrder := []string{
		"DOCKER_REGISTRY", "USE_LOCAL_LLM",
		"MAX_SCRAPE_TOKENS", "MEMORY_EXTRACTION_THRESHOLD_TOKENS", "CHAT_ROLLING_WINDOW_LIMIT", "CONDENSATION_KEEP_LIMIT",
		"LLM_API_URL", "LLM_MODEL_NAME", "LLM_CTX_SIZE", "MAX_MEMORIES_LIMIT", "DEFAULT_CHAT_TEMP", "AGENT_DECIDER_TEMP",
		"AGENT_MEMORY_SELECTOR_TEMP", "AGENT_CONDENSER_TEMP", "AGENT_EXTRACTOR_TEMP", "MAX_SEARCH_RESULTS_TO_SCRAPE",
		"DB_NAME", "DB_USER", "DB_PASS", "MYSQL_ROOT_PASSWORD", "DB_HOST", "REDIS_HOST", "SEARXNG_HOST", "FLARESOLVERR_HOST",
		"TZ", "LOG_LEVEL",
	}

	for _, k := range keysOrder {
		buf.WriteString(fmt.Sprintf("%s=%s\n", k, defaults[k]))
	}

	for k, v := range defaults {
		found := false
		for _, expected := range keysOrder {
			if expected == k {
				found = true
				break
			}
		}
		if !found {
			buf.WriteString(fmt.Sprintf("%s=%s\n", k, v))
		}
	}

	_ = os.WriteFile(envPath, buf.Bytes(), 0644)
	return defaults["DOCKER_REGISTRY"], ctxSize, useLocal
}

func startDockerCompose(workDir, binDir, registry string) {
	wslWorkDir := toWslPath(workDir)

	pullArgs := []string{
		"-d", "localsy-docker-backend",
		"-u", "root",
		"docker", "compose",
		"--project-directory", wslWorkDir,
		"-f", wslWorkDir + "/docker-compose.yml",
		"pull", "mysql", "redis", "searxng", "flaresolverr",
	}

	if strings.ToLower(strings.TrimSpace(registry)) != "local" && registry != "" {
		pullArgs = append(pullArgs, "web")
	}

	pullCmd := runSilentCommand("wsl", pullArgs...)
	pullCmd.Stdout = logFile
	pullCmd.Stderr = logFile
	_ = pullCmd.Run()

	upArgs := []string{
		"-d", "localsy-docker-backend",
		"-u", "root",
		"docker", "compose",
		"--project-directory", wslWorkDir,
		"-f", wslWorkDir + "/docker-compose.yml",
		"up", "-d",
	}
	upCmd := runSilentCommand("wsl", upArgs...)
	upCmd.Stdout = logFile
	upCmd.Stderr = logFile
	_ = upCmd.Run()
}

func startLlamaServer(binDir string, modelPath string, mmprojPath string, modelName string, ctxSize int) *exec.Cmd {
	serverPath := filepath.Join(binDir, "llama-server.exe")

	if _, err := os.Stat(serverPath); os.IsNotExist(err) {
		return nil
	}

	args := []string{
		"-m", modelPath,
		"--alias", modelName,
		"--ctx-size", strconv.Itoa(ctxSize), 
		"-ngl", "99",
		"--parallel", "1",
		"--flash-attn", "on",
		"--cache-type-k", "q8_0",
		"--cache-type-v", "q8_0", 
		"--host", "0.0.0.0",
		"--port", "1234",
		"--mlock",
	}

	// Safely inject projector path to launch llama.cpp's vision system
	if mmprojPath != "" {
		args = append(args, "--mmproj", mmprojPath)
	}

	cmd := runSilentCommand(serverPath, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	cmd.Stdout = logFile
	cmd.Stderr = logFile

	_ = cmd.Start()
	return cmd
}

func downloadFile(filepath string, url string, onProgress func(float64)) error {
	out, err := os.Create(filepath)
	if err != nil {
		return err
	}
	defer out.Close()

	resp, err := http.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	contentLength, _ := strconv.ParseInt(resp.Header.Get("Content-Length"), 10, 64)

	pw := &ProgressWriter{
		Writer:     out,
		Length:     contentLength,
		OnProgress: onProgress,
	}

	_, err = io.Copy(pw, resp.Body)
	return err
}

func unzip(src string, dest string) error {
	r, err := zip.OpenReader(src)
	if err != nil {
		return err
	}
	defer r.Close()

	cleanDest := strings.ToLower(filepath.Clean(dest))

	for _, f := range r.File {
		fpath := filepath.Join(dest, filepath.Clean(f.Name))
		cleanFpath := strings.ToLower(filepath.Clean(fpath))
		if !strings.HasPrefix(cleanFpath, cleanDest) {
			continue
		}

		if f.FileInfo().IsDir() {
			_ = os.MkdirAll(fpath, os.ModePerm)
			continue
		}

		if err = os.MkdirAll(filepath.Dir(fpath), os.ModePerm); err != nil {
			return err
		}

		outFile, err := os.OpenFile(fpath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.Mode())
		if err != nil {
			return err
		}

		rc, err := f.Open()
		if err != nil {
			outFile.Close()
			return err
		}

		_, err = io.Copy(outFile, rc)
		outFile.Close()
		rc.Close()
		if err != nil {
			return err
		}
	}
	return nil
}

func runSilentCommand(name string, arg ...string) *exec.Cmd {
	cmd := exec.Command(name, arg...)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		CreationFlags: 0x08000000, 
	}
	return cmd
}

type ProgressWriter struct {
	io.Writer
	Total      int64
	Length     int64
	LastPct    float64 
	OnProgress func(float64)
}

func (pw *ProgressWriter) Write(p []byte) (int, error) {
	n, err := pw.Writer.Write(p)
	pw.Total += int64(n)
	
	if pw.Length > 0 && pw.OnProgress != nil {
		pct := float64(pw.Total) / float64(pw.Length) * 100
		
		if pct-pw.LastPct >= 1.0 || pct == 100 {
			pw.LastPct = pct
			pw.OnProgress(pct)
		}
	}
	return n, err
}