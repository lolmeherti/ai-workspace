package video

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"localsy/internal/util"
)

const stubEnv = "VIDEO_STUB_CHILD"

// The stub binary is this test binary re-invoked; that keeps the child-process behaviour testable
// without yt-dlp, ffmpeg or a model on the machine.
func TestMain(m *testing.M) {
	if mode := os.Getenv(stubEnv); mode != "" {
		stubChild(mode)
		return
	}
	os.Exit(m.Run())
}

func stubChild(mode string) {
	switch mode {
	case "ok":
		fmt.Println(`{"text":" hello world","tokens":[" hello"," world"],"timestamps":[0.1,0.9]}`)
	case "fail":
		fmt.Fprintln(os.Stderr, "yt-dlp: ERROR: unable to download video data: HTTP Error 403: Forbidden")
		os.Exit(3)
	case "noisy":
		for i := 0; i < 200; i++ {
			fmt.Fprintf(os.Stderr, "noise line %04d ................................................................\n", i)
		}
		os.Exit(1)
	case "hang":
		time.Sleep(60 * time.Second)
	case "tree":
		child := exec.Command(os.Args[0])
		child.Env = append(os.Environ(), stubEnv+"=hang")
		child.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000}
		if err := child.Start(); err != nil {
			fmt.Fprintln(os.Stderr, "stub: cannot start grandchild:", err)
			os.Exit(4)
		}
		if pidFile := os.Getenv("VIDEO_STUB_PIDFILE"); pidFile != "" {
			_ = os.WriteFile(pidFile, []byte(strconv.Itoa(child.Process.Pid)), 0644)
		}
		time.Sleep(60 * time.Second)
	}
}

func TestRunChildCapturesTheTranscript(t *testing.T) {
	out := RunChildWithEnv(context.Background(), []string{stubEnv + "=ok"}, os.Args[0])
	if out.Failed() {
		t.Fatalf("a successful child reported failure: %s", out.Reason())
	}
	if out.ExitCode != 0 {
		t.Fatalf("exit code = %d, want 0", out.ExitCode)
	}

	segments, err := SegmentsFromCLI(out.Stdout)
	if err != nil {
		t.Fatalf("SegmentsFromCLI: %v", err)
	}
	if len(segments) != 1 {
		t.Fatalf("got %d segments, want 1", len(segments))
	}
	if segments[0].Text != "hello world" {
		t.Fatalf("text = %q, want %q", segments[0].Text, "hello world")
	}
	if segments[0].Start != 0.1 {
		t.Fatalf("start = %v, want 0.1", segments[0].Start)
	}
}

func TestRunChildReportsExitCodeAndStderrTail(t *testing.T) {
	out := RunChildWithEnv(context.Background(), []string{stubEnv + "=fail"}, os.Args[0])
	if !out.Failed() {
		t.Fatal("a child that exited 3 was reported as successful")
	}
	if out.ExitCode != 3 {
		t.Fatalf("exit code = %d, want 3", out.ExitCode)
	}
	if !strings.Contains(out.Stderr, "403") {
		t.Fatalf("the stderr tail lost the reason: %q", out.Stderr)
	}

	reason := out.Reason()
	if !strings.Contains(reason, "exit code 3") || !strings.Contains(reason, "403") {
		t.Fatalf("the reason a human reads is incomplete: %q", reason)
	}
}

func TestStderrTailKeepsTheEndAndNotTheWholeFlood(t *testing.T) {
	out := RunChildWithEnv(context.Background(), []string{stubEnv + "=noisy"}, os.Args[0])
	if len(out.Stderr) > stderrTailBytes {
		t.Fatalf("stderr kept %d bytes, cap is %d", len(out.Stderr), stderrTailBytes)
	}
	if !strings.Contains(out.Stderr, "noise line 0199") {
		t.Fatal("the tail dropped the last lines, which are the ones that carry the error")
	}
	if strings.Contains(out.Stderr, "noise line 0000") {
		t.Fatal("the cap kept the beginning instead of the end")
	}
}

// The stop button's server side: cancelling must return promptly and take the whole process tree,
// because a decoder left running has nothing left to receive its output.
func TestCancellationKillsTheChildTree(t *testing.T) {
	work := t.TempDir()
	pidFile := filepath.Join(work, "grandchild.pid")

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan ChildOutput, 1)
	go func() {
		done <- RunChildWithEnv(ctx, []string{stubEnv + "=tree", "VIDEO_STUB_PIDFILE=" + pidFile}, os.Args[0])
	}()

	grandchild := waitForPID(t, pidFile)
	cancel()

	select {
	case <-done:
	case <-time.After(20 * time.Second):
		t.Fatal("RunChild did not return after cancellation")
	}

	// Teardown is not instantaneous, so allow it a moment — but it must happen.
	deadline := time.Now().Add(10 * time.Second)
	for processAlive(grandchild) && time.Now().Before(deadline) {
		time.Sleep(100 * time.Millisecond)
	}
	if processAlive(grandchild) {
		t.Fatalf("the grandchild process %d survived cancellation", grandchild)
	}
}

func TestSegmentsFromCLISplitsOnAPause(t *testing.T) {
	stdout := "sherpa-onnx-offline --encoder=... \n" +
		"OfflineRecognizerConfig(...model_type=nemo_transducer...)\n" +
		"Creating recognizer ...\nStarted\nDone!\n" +
		`{"lang": "", "text": " Well, I don't wish to see it any more, observed Phebe, turning away her eyes.",` +
		` "timestamps": [0.32, 0.64, 0.72, 0.80, 0.88, 3.40, 3.60, 3.72],` +
		` "tokens": [" Well", ",", " I", " don", "'t", " wish", " to", " see"], "words": []}` + "\n" +
		"num threads: 4\nElapsed seconds: 1.6\n"

	segments, err := SegmentsFromCLI(stdout)
	if err != nil {
		t.Fatalf("SegmentsFromCLI: %v", err)
	}
	if len(segments) != 2 {
		t.Fatalf("got %d segments, want 2 (the pause starts a new one)", len(segments))
	}
	if segments[0].Text != "Well, I don't" {
		t.Fatalf("first segment = %q", segments[0].Text)
	}
	if segments[1].Text != "wish to see" {
		t.Fatalf("second segment = %q", segments[1].Text)
	}
	if segments[1].Start != 3.40 {
		t.Fatalf("second segment starts at %v, want 3.4", segments[1].Start)
	}
}

func TestSegmentsFromCLIHandlesNoiseAndJunk(t *testing.T) {
	if _, err := SegmentsFromCLI("Creating recognizer ...\nDone!\nElapsed seconds: 1.6\n"); err == nil {
		t.Fatal("output with no transcript object should be an error, not an empty transcript")
	}

	segments, err := SegmentsFromCLI(`{"text": " a short line", "tokens": [], "timestamps": []}`)
	if err != nil {
		t.Fatalf("a text-only object should still yield the text: %v", err)
	}
	if len(segments) != 1 || segments[0].Text != "a short line" {
		t.Fatalf("text-only fallback produced %+v", segments)
	}

	if segments := GroupTokens(nil, nil); segments != nil {
		t.Fatalf("no tokens should be no segments, got %+v", segments)
	}
}

func waitForPID(t *testing.T, path string) int {
	t.Helper()
	deadline := time.Now().Add(20 * time.Second)
	for time.Now().Before(deadline) {
		if data, err := os.ReadFile(path); err == nil {
			if pid, convErr := strconv.Atoi(strings.TrimSpace(string(data))); convErr == nil && pid > 0 {
				return pid
			}
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatalf("the stub never reported its child pid (%s)", path)

	return 0
}

// processAlive asks the OS, rather than assuming: a killed process that lingers is the bug this
// whole path exists to prevent.
func processAlive(pid int) bool {
	out, err := util.RunSilentCommand("tasklist", "/FI", "PID eq "+strconv.Itoa(pid), "/NH").Output()
	if err != nil {
		return false
	}

	return strings.Contains(string(out), strconv.Itoa(pid))
}
