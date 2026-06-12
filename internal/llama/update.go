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

func UpdateServer(binDir string, vendor string) {
	resp, err := http.Get("https://api.github.com/repos/ggml-org/llama.cpp/releases/latest")
	if err != nil {
		return
	}
	defer resp.Body.Close()

	var release models.GHRelease
	_ = json.NewDecoder(resp.Body).Decode(&release)

	serverExe := filepath.Join(binDir, "llama-server.exe")
	versionFile := filepath.Join(binDir, "version.txt")
	localVer, _ := os.ReadFile(versionFile)

	_, exeErr := os.Stat(serverExe)
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
	if err := download.File(mainZipPath, mainDownloadURL, nil); err != nil {
		return
	}
	defer os.Remove(mainZipPath)

	_ = download.Unzip(mainZipPath, binDir)

	if vendor == "NVIDIA" && cudartDownloadURL != "" {
		cudartZipPath := filepath.Join(binDir, "update_cudart.zip")
		if err := download.File(cudartZipPath, cudartDownloadURL, nil); err == nil {
			_ = download.Unzip(cudartZipPath, binDir)
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
