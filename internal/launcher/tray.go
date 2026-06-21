package launcher

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"syscall"
	"time"
	"unsafe"

	"github.com/getlantern/systray"

	"localsy/internal/logging"
	"localsy/internal/util"
)

type Assets struct {
	Compose []byte
	SearXNG []byte
	Models  []byte
	Icon    []byte
}

var embedded Assets

func SetAssets(a Assets) {
	embedded = a
}

func AssetsData() Assets {
	return embedded
}

func ShowErrorMessageBox(title, text string) {
	user32 := syscall.NewLazyDLL("user32.dll")
	messageBox := user32.NewProc("MessageBoxW")
	_, _, _ = messageBox.Call(
		0,
		uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr(text))),
		uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr(title))),
		0x00000010,
	)
}

func OpenBrowser(url string) {
	cmd := util.RunSilentCommand("cmd", "/c", "start", url)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Run()
}

func SpawnLogTerminal(logFilePath string) {
	cmd := util.RunSilentCommand("cmd", "/c", "start", "powershell", "-NoExit", "-Command", fmt.Sprintf("Get-Content -Path '%s' -Wait", logFilePath))
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Start()
}

// syncDevEnvBack copies the workspace .env used by the dev container back to
// the launcher source .env so settings changes survive the next dev launch.
// It only runs when run-dev.bat sets LOCALSY_DEV_ENV, and only if the dev
// copy is newer than the source copy.
func syncDevEnvBack() {
	devEnv := os.Getenv("LOCALSY_DEV_ENV")
	if devEnv == "" {
		return
	}

	appData := os.Getenv("LOCALAPPDATA")
	if appData == "" {
		return
	}
	sourceEnv := filepath.Join(appData, "localsy", ".env")

	data, err := os.ReadFile(devEnv)
	if err != nil {
		return
	}

	current, err := os.ReadFile(sourceEnv)
	if err == nil && bytes.Equal(current, data) {
		return
	}

	_ = os.WriteFile(sourceEnv, data, 0644)
}

func OnReady() {
	systray.SetIcon(embedded.Icon)
	systray.SetTitle("Localsy AI Engine")
	systray.SetTooltip("Localsy is running background services")

	mOpen := systray.AddMenuItem("Open Web Interface", "Launch browser to Localsy")
	mLogs := systray.AddMenuItem("View Live Logs", "Open scrolling logs window")
	systray.AddSeparator()
	mExit := systray.AddMenuItem("Shut Down", "Stop all background engines and exit")

	go Bootstrap()

	go func() {
		for {
			select {
			case <-mOpen.ClickedCh:
				OpenBrowser("http://localhost:8080")
			case <-mLogs.ClickedCh:
				appData := os.Getenv("LOCALAPPDATA")
				logFilePath := fmt.Sprintf("%s\\localsy\\localsy.log", appData)
				SpawnLogTerminal(logFilePath)
			case <-mExit.ClickedCh:
				systray.Quit()
			}
		}
	}()
}

func OnExit() {
	util.LogPrint("[!] %s: Shut Down initiated. Cleaning up background services...\n", time.Now().Format("2006-01-02 15:04:05"))

	syncDevEnvBack()

	if LlamaProcess != nil && LlamaProcess.Process != nil {
		done := make(chan error, 1)
		go func() {
			done <- LlamaProcess.Wait()
		}()

		select {
		case <-done:
			util.LogPrint("[+] AI GPU engine shut down cleanly.\n")
		case <-time.After(3 * time.Second):
			_ = LlamaProcess.Process.Kill()
		}
	}

	cmdWsl := util.RunSilentCommand("wsl", "--terminate", "localsy-docker-backend")
	cmdWsl.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdWsl.Run()

	if logging.File != nil {
		_ = logging.File.Close()
	}
}

func InitLogging(workDir string) {
	logFilePath := filepath.Join(workDir, "localsy.log")
	var err error
	logging.File, err = os.OpenFile(logFilePath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
	if err == nil {
		logging.Writer = io.MultiWriter(logging.File, os.Stdout)
		if DebugMode && os.Getenv("LOCALSY_DEV_ENV") == "" {
			SpawnLogTerminal(logFilePath)
		}
	}
}
