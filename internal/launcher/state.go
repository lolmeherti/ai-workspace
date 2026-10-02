package launcher

import "os/exec"

var (
	DockerProcess *exec.Cmd
	LlamaProcess  *exec.Cmd
	StrataProcess *exec.Cmd
	DebugMode     bool
)
