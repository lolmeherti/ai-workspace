package process

import (
	"fmt"
	"os"
	"syscall"

	"localsy/internal/util"
)

func KillDuplicateLauncherInstances() {
	myPid := os.Getpid()
	cmd := util.RunSilentCommand("powershell", "-Command",
		fmt.Sprintf(`Get-Process -Name "localsy" -ErrorAction SilentlyContinue | Where-Object { $_.Id -ne %d } | Stop-Process -Force`, myPid))
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmd.Run()
}

func KillOrphanedProcesses() {
	cmdTaskkill := util.RunSilentCommand("taskkill", "/F", "/IM", "llama-server.exe")
	cmdTaskkill.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdTaskkill.Run()

	cmdWsl := util.RunSilentCommand("wsl", "--terminate", "localsy-docker-backend")
	cmdWsl.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	_ = cmdWsl.Run()
}
