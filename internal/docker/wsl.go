package docker

import (
	"bytes"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"localsy/internal/download"
	"localsy/internal/logging"
	"localsy/internal/util"
)

func EnsureHeadlessReady(binDir, workDir string) {
	distroRegistered := IsDistroRegistered("localsy-docker-backend")
	wslDir := filepath.Join(workDir, "wsl")
	vhdxPath := filepath.Join(wslDir, "ext4.vhdx")
	_, vhdxErr := os.Stat(vhdxPath)

	if distroRegistered && os.IsNotExist(vhdxErr) {
		_ = util.RunSilentCommand("wsl", "--unregister", "localsy-docker-backend").Run()
		distroRegistered = false
	}

	if !distroRegistered {
		rootfsPath := filepath.Join(workDir, "alpine-rootfs.tar.gz")
		if _, err := os.Stat(rootfsPath); os.IsNotExist(err) {
			_ = download.File(rootfsPath, "https://dl-cdn.alpinelinux.org/alpine/v3.21/releases/x86_64/alpine-minirootfs-3.21.0-x86_64.tar.gz", nil)
		}

		_ = os.MkdirAll(wslDir, 0755)
		_ = util.RunSilentCommand("wsl", "--import", "localsy-docker-backend", wslDir, rootfsPath, "--version", "2").Run()
		_ = os.Remove(rootfsPath)

		_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "sh", "-c", "sed -i 's/#http/http/g' /etc/apk/repositories").Run()
		_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "apk", "update").Run()
		_ = util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "apk", "add", "--no-cache", "docker", "docker-cli-compose", "iptables", "ip6tables").Run()
	}
}

func IsDistroRegistered(distroName string) bool {
	cmd := util.RunSilentCommand("wsl", "--list", "--quiet")
	var out bytes.Buffer
	cmd.Stdout = &out
	_ = cmd.Run()

	output := strings.ReplaceAll(out.String(), "\x00", "")
	return strings.Contains(output, distroName)
}

func StartHeadlessDaemon() *exec.Cmd {
	cmd := util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "dockerd",
		"--host=unix:///var/run/docker.sock",
		"--host=tcp://127.0.0.1:2375",
		"--iptables=false")

	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	cmd.Stdout = logging.File
	cmd.Stderr = logging.File
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
