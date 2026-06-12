package launcher

import "os/exec"

var (
	DockerProcess *exec.Cmd
	LlamaProcess  *exec.Cmd
	DebugMode     bool
)
