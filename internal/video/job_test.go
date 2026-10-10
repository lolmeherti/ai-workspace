package video

import (
	"context"
	"errors"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

// stubWorker stands in for the yt-dlp + sherpa-onnx pipeline, so the job's own guarantees can be
// tested without a network, a model or a decoder: it spawns a real (stub) child, publishes progress,
// and can be told to panic.
type stubWorker struct {
	mode       string
	panicOnRun bool
	hang       bool

	mu         sync.Mutex
	childPID   int
	lastStatus string
	stages     []Stage
}

func (w *stubWorker) Run(ctx context.Context, job *Job, ws *Workspace) (*Result, error) {
	if w.panicOnRun {
		panic("worker blew up")
	}

	w.record(job, StageAudio)
	audio, err := ws.File("audio.wav")
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile(audio, []byte("fake audio"), 0644); err != nil {
		return nil, err
	}

	w.record(job, StageTranscribe)

	if w.hang {
		<-ctx.Done()
		return nil, errCancelled
	}

	out := RunChildWithEnv(ctx, []string{stubEnv + "=" + w.mode}, os.Args[0])
	if ctx.Err() != nil {
		return nil, errCancelled
	}
	if out.Failed() {
		return nil, errors.New(out.Reason())
	}

	segments, err := SegmentsFromCLI(out.Stdout)
	if err != nil {
		return nil, err
	}

	return &Result{
		Metadata: Metadata{Title: "A video", Language: "en"},
		Manual:   []Segment{{Start: 0, End: 1, Text: "the manual line"}},
		ASR:      segments,
	}, nil
}

func (w *stubWorker) record(job *Job, stage Stage) {
	snap := job.Snapshot()
	w.mu.Lock()
	w.stages = append(w.stages, stage)
	w.lastStatus = snap.Status
	w.mu.Unlock()
	job.SetStage(stage, string(stage)+"…")
}

func TestJobRunsToCompletionAndDeletesTheMedia(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{mode: "ok"})

	job, err := manager.Start("https://www.youtube.com/watch?v=abc")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	waitDone(t, job)

	snap := job.Snapshot()
	if snap.Stage != StageDone {
		t.Fatalf("stage = %q, want done (error: %s)", snap.Stage, snap.Error)
	}
	if snap.Result == nil || len(snap.Result.ASR) != 1 || snap.Result.ASR[0].Text != "hello world" {
		t.Fatalf("the transcript did not come through: %+v", snap.Result)
	}
	if snap.ElapsedMS < 0 {
		t.Fatalf("elapsed = %d", snap.ElapsedMS)
	}
	if snap.MediaFiles == 0 {
		t.Fatal("the job did not report deleting the media it wrote")
	}
	if snap.MediaLeft != 0 || snap.MediaError != "" {
		t.Fatalf("media left behind: %d (%s)", snap.MediaLeft, snap.MediaError)
	}
	if files, bytes := Verify(work); files != 0 || bytes != 0 {
		t.Fatalf("verify found %d files / %d bytes after a successful watch", files, bytes)
	}
}

// The stop button: cancel by id, and the media goes with it.
func TestCancelStopsTheJobAndDeletesTheMedia(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{hang: true})

	job, err := manager.Start("https://www.youtube.com/watch?v=abc")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	waitStage(t, job, StageTranscribe)

	if !manager.Cancel(job.ID) {
		t.Fatal("Cancel refused a running job, which is the whole stop button")
	}
	waitDone(t, job)

	snap := job.Snapshot()
	if snap.Stage != StageCancelled {
		t.Fatalf("stage = %q, want cancelled", snap.Stage)
	}
	if snap.Error != "" {
		t.Fatalf("a cancellation is not an error: %q", snap.Error)
	}
	if files, _ := Verify(work); files != 0 {
		t.Fatalf("%d media files survived the cancellation", files)
	}
	if snap.MediaLeft != 0 {
		t.Fatalf("the job reports %d files left behind", snap.MediaLeft)
	}
}

func TestCancelIsHonestAboutWhatItCannotDo(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{mode: "ok"})

	job, err := manager.Start("https://youtu.be/abc")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	waitDone(t, job)
	if manager.Cancel(job.ID) {
		t.Fatal("Cancel claimed to stop a job that had already finished")
	}
	if manager.Cancel("no-such-job") {
		t.Fatal("Cancel claimed to stop a job that does not exist")
	}
}

// A panic in the worker must fail the job, not the launcher, and must not skip the media deletion.
func TestWorkerPanicFailsTheJobAndStillDeletesTheMedia(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{panicOnRun: true})

	job, err := manager.Start("https://youtu.be/abc")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	waitDone(t, job)

	snap := job.Snapshot()
	if snap.Stage != StageFailed {
		t.Fatalf("stage = %q, want failed", snap.Stage)
	}
	if !strings.Contains(snap.Error, "worker blew up") {
		t.Fatalf("the panic was not recorded as the reason: %q", snap.Error)
	}
	if !strings.Contains(snap.Stack, "stubWorker") {
		t.Fatalf("the panic stack was not kept for /logs: %q", snap.Stack)
	}
	if files, _ := Verify(work); files != 0 {
		t.Fatalf("%d media files survived a panicking job", files)
	}
}

func TestFailedChildBecomesAJobErrorWithItsExitCode(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{mode: "fail"})

	job, err := manager.Start("https://youtu.be/abc")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	waitDone(t, job)

	snap := job.Snapshot()
	if snap.Stage != StageFailed {
		t.Fatalf("stage = %q, want failed", snap.Stage)
	}
	if !strings.Contains(snap.Error, "exit code 3") || !strings.Contains(snap.Error, "403") {
		t.Fatalf("the job error lost the diagnosis: %q", snap.Error)
	}
}

func TestStartRefusesWhatItCannotDo(t *testing.T) {
	if _, err := NewManager(t.TempDir(), &stubWorker{mode: "ok"}).Start("   "); err == nil {
		t.Fatal("a blank url should be refused")
	}
	if _, err := NewManager(t.TempDir(), nil).Start("https://youtu.be/abc"); err == nil {
		t.Fatal("starting without a transcription backend should be refused, not a hung job")
	}
}

// One directory per job, and both cleaned: the isolation the retention rule depends on.
func TestConcurrentJobsGetTheirOwnWorkspaces(t *testing.T) {
	work := t.TempDir()
	manager := NewManager(work, &stubWorker{mode: "ok"})

	first, err := manager.Start("https://youtu.be/one")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	second, err := manager.Start("https://youtu.be/two")
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	if first.ID == second.ID {
		t.Fatal("two jobs share an id, so they share a workspace")
	}

	waitDone(t, first)
	waitDone(t, second)

	if got, ok := manager.Get(first.ID); !ok || got.ID != first.ID {
		t.Fatal("Get lost a job")
	}
	if _, ok := manager.Get("no-such-job"); ok {
		t.Fatal("Get invented a job")
	}
	if files, _ := Verify(work); files != 0 {
		t.Fatalf("%d media files survived two concurrent jobs", files)
	}
}

func waitDone(t *testing.T, job *Job) {
	t.Helper()
	select {
	case <-job.Done():
	case <-time.After(60 * time.Second):
		t.Fatalf("job %s never finished (stage %q)", job.ID, job.Snapshot().Stage)
	}
}

func waitStage(t *testing.T, job *Job, stage Stage) {
	t.Helper()
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		if job.Snapshot().Stage == stage {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("job %s never reached stage %q (stuck at %q)", job.ID, stage, job.Snapshot().Stage)
}
