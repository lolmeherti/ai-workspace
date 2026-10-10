package video

import (
	"context"
	"errors"
	"fmt"
	"os/exec"
	"strconv"
	"strings"
	"syscall"

	"localsy/internal/util"
)

const (
	stderrTailBytes = 2048
	stdoutTailBytes = 4 << 20 // the decoder prints the whole transcript on stdout
)

// ChildOutput is what a host binary said. For yt-dlp, ffmpeg and sherpa-onnx the useful diagnosis
// is the exit code plus the tail of stderr, not a stack trace.
type ChildOutput struct {
	Stdout    string
	Stderr    string
	ExitCode  int
	Err       error
	Truncated bool
}

func (o ChildOutput) Failed() bool { return o.Err != nil || o.ExitCode != 0 }

// Reason is the one-line explanation that goes into the job error and therefore into /logs.
func (o ChildOutput) Reason() string {
	if o.Err != nil && o.ExitCode <= 0 && strings.TrimSpace(o.Stderr) == "" {
		return o.Err.Error()
	}

	parts := []string{fmt.Sprintf("exit code %d", o.ExitCode)}
	if tail := strings.TrimSpace(o.Stderr); tail != "" {
		parts = append(parts, "stderr: "+tail)
	}

	return strings.Join(parts, " — ")
}

// RunChild runs a host binary to completion. Cancelling ctx kills the whole process tree, not just
// the parent: yt-dlp spawns ffmpeg, and killing only yt-dlp leaves ffmpeg decoding audio into a
// workspace nobody will collect.
func RunChild(ctx context.Context, exe string, args ...string) ChildOutput {
	return RunChildWithEnv(ctx, nil, exe, args...)
}

func RunChildWithEnv(ctx context.Context, env []string, exe string, args ...string) ChildOutput {
	// Deliberately not exec.CommandContext: its cancellation kills the direct child immediately, and a
	// dead parent cannot be enumerated — taskkill /T then finds no tree and leaves the grandchildren
	// (yt-dlp's ffmpeg) running. The tree kill has to happen while the parent is still alive.
	cmd := exec.Command(exe, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
	if len(env) > 0 {
		cmd.Env = append(cmd.Environ(), env...)
	}

	stdout := &tailWriter{max: stdoutTailBytes}
	stderr := &tailWriter{max: stderrTailBytes}
	cmd.Stdout = stdout
	cmd.Stderr = stderr

	if err := cmd.Start(); err != nil {
		return ChildOutput{Err: err, ExitCode: -1}
	}

	pid := cmd.Process.Pid
	stop := make(chan struct{})
	go func() {
		select {
		case <-ctx.Done():
			killTree(pid)
			_ = cmd.Process.Kill()
		case <-stop:
		}
	}()

	err := cmd.Wait()
	close(stop)

	// Only the payload counts as "too big". stderr is a tail by design, so a chatty child must not look
	// like an unreadable one.
	out := ChildOutput{Stdout: stdout.String(), Stderr: stderr.String(), Err: err, Truncated: stdout.dropped}
	if cmd.ProcessState != nil {
		out.ExitCode = cmd.ProcessState.ExitCode()
	} else {
		out.ExitCode = -1
	}
	if err != nil && out.ExitCode == 0 {
		out.ExitCode = -1
	}

	return out
}

// killTree takes the children with it. A cancelled watch must not leave a decoder chewing CPU with
// nothing left to receive its output.
func killTree(pid int) {
	if pid <= 0 {
		return
	}

	_ = util.RunSilentCommand("taskkill", "/F", "/T", "/PID", strconv.Itoa(pid)).Run()
}

// BinaryPresent is the preflight a stage needs before it runs: a missing yt-dlp or sherpa-onnx is a
// clear message, not an unexplained exit code 1.
func BinaryPresent(name string) bool {
	_, err := exec.LookPath(name)

	return err == nil
}

// tailWriter keeps the last max bytes. The tail is what carries the error, and an unbounded buffer
// on a chatty binary is a memory leak with extra steps.
//
// dropped records that something was thrown away, so the caller can say "this was too big to read"
// instead of failing on a half-object with a confusing parse error.
type tailWriter struct {
	max     int
	buf     []byte
	dropped bool
}

func (t *tailWriter) Write(p []byte) (int, error) {
	t.buf = append(t.buf, p...)
	if len(t.buf) > t.max {
		t.dropped = true
		t.buf = append(t.buf[:0], t.buf[len(t.buf)-t.max:]...)
	}

	return len(p), nil
}

func (t *tailWriter) String() string { return string(t.buf) }

var errCancelled = errors.New("video: cancelled")
