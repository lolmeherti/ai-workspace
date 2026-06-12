package main

import (
	_ "embed"
	"flag"

	"github.com/getlantern/systray"

	"localsy/internal/launcher"
	"localsy/internal/process"
)

//go:embed docker-compose.prod.yml
var embeddedCompose []byte

//go:embed models.json
var embeddedModels []byte

//go:embed searxng/settings.yml
var embeddedSearXNG []byte

//go:embed icon.ico
var iconData []byte

func main() {
	debugMode := flag.Bool("debug", false, "Enable verbose logging and spawn a live log terminal")
	flag.Parse()

	launcher.DebugMode = *debugMode
	launcher.SetAssets(launcher.Assets{
		Compose: embeddedCompose,
		Models:  embeddedModels,
		SearXNG: embeddedSearXNG,
		Icon:    iconData,
	})

	process.KillDuplicateLauncherInstances()
	process.KillOrphanedProcesses()

	systray.Run(launcher.OnReady, launcher.OnExit)
}
