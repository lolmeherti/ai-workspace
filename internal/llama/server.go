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

	"localsy/internal/logging"
	"localsy/internal/util"
)

func StartServer(binDir string, modelPath string, mmprojPath string, modelName string, ctxSize int) *exec.Cmd {
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

	if mmprojPath != "" {
		args = append(args, "--mmproj", mmprojPath)
	}

	cmd := util.RunSilentCommand(serverPath, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	cmd.Stdout = logging.File
	cmd.Stderr = logging.File

	_ = cmd.Start()
	return cmd
}

func WaitForReady() {
	client := http.Client{Timeout: 1 * time.Second}
	type health struct {
		Status string `json:"status"`
	}

	for i := 0; i < 180; i++ {
		resp, err := client.Get("http://127.0.0.1:1234/health")
		if err == nil {
			var h health
			errDec := json.NewDecoder(resp.Body).Decode(&h)
			_ = resp.Body.Close()
			if errDec == nil && h.Status == "ok" {
				return
			}
		}
		time.Sleep(1 * time.Second)
	}
}
