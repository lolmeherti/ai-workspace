package launcher

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/windows"

	"localsy/internal/logging"
	"localsy/internal/models"
	"localsy/internal/util"
)

// The external engine's tree is the wrapper we spawn plus everything it spawns
// under it, so stopping it means killing the tree, not the one process a
// *exec.Cmd holds. A state file records what we started so a restarted launcher
// can still find (and only then stop) it. Nothing here matches on image name.
const (
	strataStateName  = "strata-runtime.json"
	strataLoadWait   = 300 * time.Second
	strataStopWait   = 30 * time.Second
	strataPollPeriod = 2 * time.Second
	maxPathLen       = 1024
)

// externalEngineState is what we wrote when we started the engine. PID alone is
// not an identity (Windows reuses them), so the creation time, the executable
// and the arguments we launched are recorded alongside it.
type externalEngineState struct {
	PID          int      `json:"pid"`
	CreationTime int64    `json:"creation_time"` // Windows FILETIME ticks
	Exe          string   `json:"exe"`
	Args         []string `json:"args"`
	StartedAt    string   `json:"started_at"`
	Port         int      `json:"port"`
}

// startStrata launches an external engine's HTTP wrapper and records the tree it
// starts. Returns nil when the wrapper could not be spawned at all.
func startStrata(e *models.EngineSpec, workDir string) *exec.Cmd {
	args := strataArgs(e)
	cmd := exec.Command(e.Python, args...)
	// serve/server.py lives in the engine repo; run from the repo root, which is
	// what the engine's own launcher script does.
	cmd.Dir = filepath.Dir(filepath.Dir(e.Script))
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	cmd.Stdout = logging.File
	cmd.Stderr = logging.File

	util.LogPrint("[+] starting %s engine: %s %s\n", e.Type, e.Python, strings.Join(args, " "))
	if err := cmd.Start(); err != nil {
		util.LogPrint("[-] %s engine failed to start: %v\n", e.Type, err)
		return nil
	}
	writeEngineState(workDir, cmd.Process.Pid, e.Python, args, e.Port)
	return cmd
}

// strataArgs are the wrapper's arguments. --host 0.0.0.0 is required: the app
// runs in Docker and reaches the engine over the host's WSL adapter, so a
// loopback-only bind would be unreachable. It also matches llama-server, which
// this app already starts on 0.0.0.0.
func strataArgs(e *models.EngineSpec) []string {
	return []string{
		e.Script,
		"--engine", e.Type,
		"--config", e.Config,
		"--port", strconv.Itoa(e.Port),
		"--host", "0.0.0.0",
	}
}

// WaitStrataReady blocks until the engine reports the model loaded. The wrapper
// can take ~90 s to copy the experts into RAM, so this is a long, bounded wait.
// A wrapper that dies while we wait (a busy port, a bad config) fails the switch
// instead of being mistaken for a healthy server.
func waitStrataReady(port int, pid uint32) bool {
	deadline := time.Now().Add(strataLoadWait)
	client := http.Client{Timeout: 3 * time.Second}
	for time.Now().Before(deadline) {
		if pid != 0 && !processAliveByID(pid) {
			util.LogPrint("[-] engine wrapper (pid %d) exited before the model was ready\n", pid)
			return false
		}
		if ok, info := strataHealth(&client, port); ok {
			util.LogPrint("[+] engine ready on port %d (%s)\n", port, info)
			return true
		}
		time.Sleep(strataPollPeriod)
	}
	util.LogPrint("[-] engine on port %d did not report the model loaded within %s\n", port, strataLoadWait)
	return false
}

// strataHealth probes the wrapper's /health. Readiness is `loaded`, not merely
// an answer: the wrapper serves 200 while the engine is still unloaded.
func strataHealth(client *http.Client, port int) (bool, string) {
	resp, err := client.Get(fmt.Sprintf("http://127.0.0.1:%d/health", port))
	if err != nil {
		return false, ""
	}
	defer resp.Body.Close()
	var h struct {
		Status string `json:"status"`
		Model  string `json:"model"`
		Loaded bool   `json:"loaded"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&h); err != nil {
		return false, ""
	}
	if h.Status == "ok" && h.Loaded {
		return true, h.Model
	}
	return false, ""
}

// stopOwnedRuntime stops the external-engine tree this launcher started: the
// in-memory handle when we still have one, otherwise the one the state file
// points at. The process is only killed after its identity is confirmed against
// what we recorded — a reused pid must never cost someone else a process.
func stopOwnedRuntime(workDir string, cmd *exec.Cmd) {
	statePath := filepath.Join(workDir, strataStateName)
	pid := 0
	if cmd != nil && cmd.Process != nil {
		pid = cmd.Process.Pid
	} else {
		st, ok := readEngineState(statePath)
		if !ok {
			return
		}
		if !processAliveByID(uint32(st.PID)) {
			removeEngineState(statePath)
			return
		}
		if !engineIdentityMatches(st) {
			util.LogPrint("[!] refusing to stop pid %d: it does not match the engine recorded in %s — a process we do not own may be holding the GPU\n",
				st.PID, statePath)
			removeEngineState(statePath)
			return
		}
		pid = st.PID
	}

	if pid == 0 {
		return
	}
	util.LogPrint("[+] stopping external engine tree (pid %d)\n", pid)
	// /T takes the wrapper's children (the engine itself) with it; /F because a
	// console process does not handle the polite close.
	_ = util.RunSilentCommand("taskkill", "/T", "/F", "/PID", strconv.Itoa(pid)).Run()

	if !waitForProcessExit(uint32(pid), strataStopWait) {
		util.LogPrint("[-] pid %d did not exit within %s — the next engine may fail on an occupied GPU\n", pid, strataStopWait)
	}
	if cmd != nil {
		reap(cmd)
	}
	removeEngineState(statePath)
}

// reap releases the *exec.Cmd handle without blocking on a process that is
// already gone.
func reap(cmd *exec.Cmd) {
	done := make(chan struct{})
	go func() {
		_ = cmd.Wait()
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
	}
}

func writeEngineState(workDir string, pid int, exe string, args []string, port int) {
	st := externalEngineState{
		PID:       pid,
		Exe:       exe,
		Args:      args,
		StartedAt: time.Now().Format(time.RFC3339),
		Port:      port,
	}
	if ct, err := processCreationTime(uint32(pid)); err == nil {
		st.CreationTime = ct
	} else {
		util.LogPrint("[!] could not read the creation time of pid %d: %v (a launcher restart will not be able to identify it)\n", pid, err)
	}
	b, err := json.Marshal(st)
	if err != nil {
		return
	}
	if err := os.WriteFile(filepath.Join(workDir, strataStateName), b, 0644); err != nil {
		util.LogPrint("[!] could not write %s: %v\n", strataStateName, err)
	}
}

func readEngineState(path string) (externalEngineState, bool) {
	var st externalEngineState
	b, err := os.ReadFile(path)
	if err != nil {
		return st, false
	}
	if err := json.Unmarshal(b, &st); err != nil || st.PID == 0 {
		util.LogPrint("[!] ignoring unreadable engine state file %s\n", path)
		return st, false
	}
	return st, true
}

func removeEngineState(path string) {
	_ = os.Remove(path)
}

// engineIdentityMatches confirms that the pid in the state file is still the
// process we started: same creation time, same executable, and the arguments we
// launched still on its command line.
func engineIdentityMatches(st externalEngineState) bool {
	if st.CreationTime != 0 {
		ct, err := processCreationTime(uint32(st.PID))
		if err != nil || ct != st.CreationTime {
			return false
		}
	}
	if st.Exe != "" {
		img, err := processImagePath(uint32(st.PID))
		if err != nil || !strings.EqualFold(filepath.Clean(img), filepath.Clean(st.Exe)) {
			return false
		}
	}
	if line := processCommandLine(uint32(st.PID)); line != "" {
		for _, arg := range st.Args {
			if !strings.Contains(line, arg) {
				return false
			}
		}
	}
	return true
}

// processCreationTime returns a process's creation time as Windows FILETIME
// ticks. (pid, creation time) is the documented unique identity of a process on
// Windows, where pids are recycled.
func processCreationTime(pid uint32) (int64, error) {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION, false, pid)
	if err != nil {
		return 0, err
	}
	defer windows.CloseHandle(h)
	var creation, exit, kernel, user windows.Filetime
	if err := windows.GetProcessTimes(h, &creation, &exit, &kernel, &user); err != nil {
		return 0, err
	}
	return creation.Nanoseconds(), nil
}

func processImagePath(pid uint32) (string, error) {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION, false, pid)
	if err != nil {
		return "", err
	}
	defer windows.CloseHandle(h)
	buf := make([]uint16, maxPathLen)
	size := uint32(len(buf))
	if err := windows.QueryFullProcessImageName(h, 0, &buf[0], &size); err != nil {
		return "", err
	}
	return syscall.UTF16ToString(buf[:size]), nil
}

// processCommandLine is a best-effort read of a process's command line. Empty
// means "could not tell", never "no match" — the identity check already rests on
// pid + creation time + executable.
func processCommandLine(pid uint32) string {
	var out bytes.Buffer
	cmd := util.RunSilentCommand("powershell", "-NoProfile", "-Command",
		fmt.Sprintf("(Get-CimInstance Win32_Process -Filter \"ProcessId=%d\").CommandLine", pid))
	cmd.Stdout = &out
	if err := cmd.Run(); err != nil {
		return ""
	}
	return strings.TrimSpace(out.String())
}

func processAliveByID(pid uint32) bool {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_INFORMATION, false, pid)
	if err != nil {
		return false
	}
	defer windows.CloseHandle(h)
	var exitCode uint32
	if err := windows.GetExitCodeProcess(h, &exitCode); err != nil {
		return false
	}
	return exitCode == 259 // STILL_ACTIVE
}

func waitForProcessExit(pid uint32, timeout time.Duration) bool {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if !processAliveByID(pid) {
			return true
		}
		time.Sleep(250 * time.Millisecond)
	}
	return !processAliveByID(pid)
}
