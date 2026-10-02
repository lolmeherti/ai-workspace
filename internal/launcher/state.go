package launcher

import "os/exec"

var (
	DockerProcess *exec.Cmd
	LlamaProcess  *exec.Cmd
	StrataProcess *exec.Cmd
	DebugMode     bool

	// BootSampling / BootRuntimePolicy hold the per-model sampling and reasoning policy the
	// launcher resolved for the model it started at boot. /api/switch-status publishes them
	// when no switch is in flight, so the web layer can resolve the Reasoning control's
	// graduated levels on a cold start instead of falling back to Off/On. Written once before
	// StartHTTPServer begins serving; cleared when a switch takes over.
	BootSampling      string
	BootRuntimePolicy string
)
