package llama

import (
	"encoding/json"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
	"time"

	"golang.org/x/sys/windows"

	"localsy/internal/logging"
	"localsy/internal/models"
	"localsy/internal/util"
)

func StartServer(binDir string, m *models.ResolvedModel) *exec.Cmd {
	serverPath := filepath.Join(binDir, "llama-server.exe")
	if _, err := os.Stat(serverPath); os.IsNotExist(err) {
		return nil
	}

	args := []string{
		"-m", m.ModelPath,
		"--alias", m.Name,
		"--ctx-size", strconv.Itoa(m.CtxSize),
		"-ngl", "999",
		"--parallel", "1",
		"--host", "0.0.0.0",
		"--port", "1234",
		"--jinja",
	}

	if m.ReasoningBudget > 0 {
		args = append(args, "--reasoning-budget", strconv.Itoa(m.ReasoningBudget))
	}

	if m.FlashAttn {
		args = append(args, "--flash-attn", "on")
	}

	if m.KVCacheType != "" {
		args = append(args, "--cache-type-k", m.KVCacheType, "--cache-type-v", m.KVCacheType)
	}

	if m.MMProjPath != "" {
		if _, err := os.Stat(m.MMProjPath); err == nil {
			args = append(args, "--mmproj", m.MMProjPath)
		}
	}

	if m.Speculative != nil {
		if _, err := os.Stat(m.Speculative.Path); err == nil {
			strategy := m.Speculative.Strategy
			if strategy == "" {
				strategy = "draft-mtp"
			}
			args = append(args,
				"--spec-type", strategy,
				"--spec-draft-model", m.Speculative.Path,
				"--spec-draft-n-max", strconv.Itoa(m.Speculative.NMax),
				"--spec-draft-ngl", strconv.Itoa(m.Speculative.NGL),
			)
		}
	}

	args = append(args, m.ExtraArgs...)

	cmd := util.RunSilentCommand(serverPath, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		CreationFlags: 0x08000000,
	}
	cmd.Stdout = logging.File
	cmd.Stderr = logging.File

	_ = cmd.Start()
	return cmd
}

func StartServerWithFallback(binDir string, m *models.ResolvedModel) *exec.Cmd {
	cmd := StartServer(binDir, m)
	if cmd == nil {
		return nil
	}

	if m.Speculative != nil {
		util.LogPrint("[+] starting with speculative decoding (%s, n_max=%d)\n",
			m.Speculative.Strategy, m.Speculative.NMax)
		if !waitForReady(30) {
			if !processAlive(cmd) {
				util.LogPrint("[!] speculative model %s caused crash at startup, retrying without it\n",
					m.Speculative.Path)
				m.Speculative = nil
				cmd = StartServer(binDir, m)
				if cmd != nil {
					if WaitForReady() {
						util.LogPrint("[+] fallback startup succeeded (target-only mode)\n")
					} else {
						util.LogPrint("[-] fallback startup also failed\n")
					}
				}
				return cmd
			}
		}
	}

	WaitForReady()
	return cmd
}

func processAlive(cmd *exec.Cmd) bool {
	if cmd.Process == nil {
		return false
	}
	handle, err := windows.OpenProcess(windows.PROCESS_QUERY_INFORMATION, false, uint32(cmd.Process.Pid))
	if err != nil {
		return false
	}
	defer windows.CloseHandle(handle)
	var exitCode uint32
	if err := windows.GetExitCodeProcess(handle, &exitCode); err != nil {
		return false
	}
	return exitCode == 259 // STILL_ACTIVE
}

func WaitForReady() bool {
	return waitForReady(180)
}

func waitForReady(maxSeconds int) bool {
	client := http.Client{Timeout: 1 * time.Second}
	type health struct {
		Status string `json:"status"`
	}
	for i := 0; i < maxSeconds; i++ {
		resp, err := client.Get("http://127.0.0.1:1234/health")
		if err == nil {
			var h health
			errDec := json.NewDecoder(resp.Body).Decode(&h)
			_ = resp.Body.Close()
			if errDec == nil && h.Status == "ok" {
				return true
			}
		}
		time.Sleep(1 * time.Second)
	}
	return false
}

func KillIfRunning(process **exec.Cmd) {
	if *process != nil && (*process).Process != nil {
		(*process).Process.Kill()
		(*process).Wait()
		*process = nil
	}
}
