package llama

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"localsy/internal/download"
	"localsy/internal/models"
)

// UpdateServer downloads the newest llama-server release that ships a Windows
// binary for the given GPU vendor. It scans the release list (newest first)
// rather than trusting "releases/latest": llama.cpp switched from b-number tags
// to semver, and the "latest" release can lack Windows assets entirely.
func UpdateServer(binDir string, vendor string) {
	resp, err := http.Get("https://api.github.com/repos/ggml-org/llama.cpp/releases?per_page=40")
	if err != nil {
		return
	}
	defer resp.Body.Close()

	var releases []models.GHRelease
	_ = json.NewDecoder(resp.Body).Decode(&releases)

	serverExe := filepath.Join(binDir, "llama-server.exe")
	versionFile := filepath.Join(binDir, "version.txt")

	for _, release := range releases {
		mainURL, cudartURL := windowsAssets(release, vendor)
		if mainURL == "" {
			continue
		}

		localVer, _ := os.ReadFile(versionFile)
		if _, exeErr := os.Stat(serverExe); exeErr == nil && string(localVer) == release.TagName {
			// Already on this release.
			return
		}

		mainZipPath := filepath.Join(binDir, "update_main.zip")
		if err := download.File(mainZipPath, mainURL, nil); err != nil {
			return
		}
		defer os.Remove(mainZipPath)

		_ = download.Unzip(mainZipPath, binDir)

		if vendor == "NVIDIA" && cudartURL != "" {
			cudartZipPath := filepath.Join(binDir, "update_cudart.zip")
			if err := download.File(cudartZipPath, cudartURL, nil); err == nil {
				_ = download.Unzip(cudartZipPath, binDir)
				os.Remove(cudartZipPath)
			}
		}

		flattenBinDir(binDir)

		if _, err := os.Stat(serverExe); err == nil {
			_ = os.WriteFile(versionFile, []byte(release.TagName), 0644)
		}
		return
	}
}

// windowsAssets returns the (main, cudart) download URLs for a release and
// vendor. NVIDIA prefers a CUDA 13.x build (native sm_120 / Blackwell kernels
// for the RTX 50 series) and falls back to CUDA 12.4. AMD uses Vulkan; any
// other vendor gets the CPU-only build.
func windowsAssets(release models.GHRelease, vendor string) (string, string) {
	switch vendor {
	case "NVIDIA":
		for _, cuda := range []string{"13", "12.4"} {
			var main, cudart string
			for _, a := range release.Assets {
				name := a.Name
				if !strings.HasSuffix(name, "x64.zip") || !strings.Contains(name, "-bin-win-cuda-"+cuda) {
					continue
				}
				switch {
				case strings.HasPrefix(name, "llama-"):
					main = a.DownloadURL
				case strings.HasPrefix(name, "cudart-"):
					cudart = a.DownloadURL
				}
			}
			if main != "" {
				return main, cudart
			}
		}
	case "AMD":
		for _, a := range release.Assets {
			if strings.Contains(a.Name, "-bin-win-vulkan-x64.zip") {
				return a.DownloadURL, ""
			}
		}
	default:
		for _, a := range release.Assets {
			if strings.Contains(a.Name, "-bin-win-cpu-x64.zip") {
				return a.DownloadURL, ""
			}
		}
	}
	return "", ""
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
