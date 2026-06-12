package docker

import (
	"strings"

	"localsy/internal/logging"
	"localsy/internal/util"
)

func StartCompose(workDir, binDir, registry string) {
	wslWorkDir := util.ToWslPath(workDir)

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

	pullCmd := util.RunSilentCommand("wsl", pullArgs...)
	pullCmd.Stdout = logging.File
	pullCmd.Stderr = logging.File
	_ = pullCmd.Run()

	upArgs := []string{
		"-d", "localsy-docker-backend",
		"-u", "root",
		"docker", "compose",
		"--project-directory", wslWorkDir,
		"-f", wslWorkDir + "/docker-compose.yml",
		"up", "-d",
	}
	upCmd := util.RunSilentCommand("wsl", upArgs...)
	upCmd.Stdout = logging.File
	upCmd.Stderr = logging.File
	_ = upCmd.Run()
}
