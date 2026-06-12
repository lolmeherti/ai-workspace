package util

import (
	"fmt"
	"os"
	"os/exec"
	"syscall"

	"localsy/internal/logging"
)

func LogPrint(format string, a ...interface{}) {
	fmt.Fprintf(logging.Writer, format, a...)
}

func RunSilentCommand(name string, arg ...string) *exec.Cmd {
	cmd := exec.Command(name, arg...)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		CreationFlags: 0x08000000,
	}
	return cmd
}

func WriteConfig(dest string, content []byte) {
	_ = os.WriteFile(dest, content, 0644)
}
