package util

import (
	"bytes"
	"path/filepath"
	"strings"
)

func GetWindowsHostIP() string {
	cmd := RunSilentCommand("wsl", "-d", "localsy-docker-backend", "ip", "route")
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

	cmdFallback := RunSilentCommand("wsl", "-d", "localsy-docker-backend", "cat", "/etc/resolv.conf")
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

func ToWslPath(winPath string) string {
	cleaned := filepath.Clean(winPath)
	vol := filepath.VolumeName(cleaned)
	if vol == "" {
		return winPath
	}
	pathWithoutVol := strings.TrimPrefix(cleaned, vol)
	driveLetter := strings.ToLower(strings.TrimSuffix(vol, ":"))
	return "/mnt/" + driveLetter + filepath.ToSlash(pathWithoutVol)
}

func ApplyWslNatRule() {
	_ = RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "iptables", "-t", "nat", "-D", "POSTROUTING", "-o", "eth0", "-j", "MASQUERADE").Run()
	_ = RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root", "iptables", "-t", "nat", "-A", "POSTROUTING", "-o", "eth0", "-j", "MASQUERADE").Run()
}
