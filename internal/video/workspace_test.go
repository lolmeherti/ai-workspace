package video

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func write(t *testing.T, path string, body string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		t.Fatalf("mkdir %s: %v", filepath.Dir(path), err)
	}
	if err := os.WriteFile(path, []byte(body), 0644); err != nil {
		t.Fatalf("write %s: %v", path, err)
	}
}

func TestWorkspaceKeepsMediaInsideItsOwnTree(t *testing.T) {
	work := t.TempDir()

	workspace, err := NewWorkspace(work, "job-123")
	if err != nil {
		t.Fatalf("NewWorkspace: %v", err)
	}
	if want := filepath.Join(work, "tmp", "watch", "job-123"); workspace.Root() != want {
		t.Fatalf("root = %q, want %q", workspace.Root(), want)
	}
	if _, err := os.Stat(workspace.Root()); err != nil {
		t.Fatalf("workspace directory was not created: %v", err)
	}

	audio, err := workspace.File("audio.wav")
	if err != nil {
		t.Fatalf("File(audio.wav): %v", err)
	}
	if want := filepath.Join(workspace.Root(), "audio.wav"); audio != want {
		t.Fatalf("File = %q, want %q", audio, want)
	}

	for _, name := range []string{"", "../escape.wav", "sub/audio.wav", `sub\audio.wav`, "/abs/audio.wav", ".hidden"} {
		if _, err := workspace.File(name); err == nil {
			t.Fatalf("File(%q) was allowed to resolve outside the workspace", name)
		}
	}
}

func TestJobIDCannotEscapeTheTempRoot(t *testing.T) {
	work := t.TempDir()

	workspace, err := NewWorkspace(work, "../../etc/passwd")
	if err != nil {
		t.Fatalf("NewWorkspace: %v", err)
	}
	if !strings.HasPrefix(workspace.Root(), TempRoot(work)+string(filepath.Separator)) {
		t.Fatalf("root escaped the temp root: %q", workspace.Root())
	}
	if strings.Contains(workspace.JobID(), "/") || strings.Contains(workspace.JobID(), "\\") {
		t.Fatalf("job id kept a path separator: %q", workspace.JobID())
	}
	if _, err := NewWorkspace(work, "///"); err == nil {
		t.Fatal("a job id with nothing usable in it should be refused")
	}
}

func TestCleanupRemovesEverythingAndSaysSo(t *testing.T) {
	work := t.TempDir()

	workspace, err := NewWorkspace(work, "job-clean")
	if err != nil {
		t.Fatalf("NewWorkspace: %v", err)
	}
	audio, _ := workspace.File("audio.wav")
	video, _ := workspace.File("clip.mp4")
	write(t, audio, strings.Repeat("a", 1000))
	write(t, video, strings.Repeat("v", 500))
	write(t, filepath.Join(workspace.Root(), "parts", "audio.opus.part"), "part")

	report := workspace.Cleanup()
	if report.Failed {
		t.Fatalf("cleanup reported failure: %s (left %d files)", report.Error, report.LeftFiles)
	}
	if report.Files != 3 {
		t.Fatalf("reported %d deleted files, want 3", report.Files)
	}
	if report.Bytes != 1504 {
		t.Fatalf("reported %d deleted bytes, want 1504", report.Bytes)
	}
	if _, err := os.Stat(workspace.Root()); !os.IsNotExist(err) {
		t.Fatalf("the workspace is still on disk: %v", err)
	}

	if files, bytes := Verify(work); files != 0 || bytes != 0 {
		t.Fatalf("verify found %d files / %d bytes still present", files, bytes)
	}
	if _, err := os.Stat(TempRoot(work)); !os.IsNotExist(err) {
		t.Fatal("the empty temp root should be gone once the last job is")
	}

	second := workspace.Cleanup()
	if second.Failed || second.Files != 0 {
		t.Fatalf("cleanup is not idempotent: failed=%v files=%d", second.Failed, second.Files)
	}
}

// A deletion that does not happen must be reported, never assumed: this is the difference between "the
// media is gone" and "we hoped the media is gone".
func TestCleanupReportsMediaItCouldNotDelete(t *testing.T) {
	work := t.TempDir()

	workspace, err := NewWorkspace(work, "job-locked")
	if err != nil {
		t.Fatalf("NewWorkspace: %v", err)
	}
	audio, _ := workspace.File("audio.wav")
	write(t, audio, strings.Repeat("a", 200))

	workspace.remove = func(string) error { return errors.New("the file is in use by another process") }

	report := workspace.Cleanup()
	if !report.Failed {
		t.Fatal("a workspace that could not be deleted was reported as deleted")
	}
	if report.LeftFiles != 1 || report.LeftBytes != 200 {
		t.Fatalf("the leftover was not measured: %d files / %d bytes", report.LeftFiles, report.LeftBytes)
	}
	if report.Attempts != cleanupRetries {
		t.Fatalf("cleanup gave up after %d attempts, want %d", report.Attempts, cleanupRetries)
	}
	if !strings.Contains(report.Error, "in use") {
		t.Fatalf("the reason was not carried into the report: %q", report.Error)
	}
	if files, _ := Verify(work); files != 1 {
		t.Fatalf("verify says %d files are gone while the media is on disk", files)
	}

	workspace.remove = os.RemoveAll
	released := workspace.Cleanup()
	if released.Failed {
		t.Fatalf("cleanup after the handle was released still failed: %s", released.Error)
	}
	if files, bytes := Verify(work); files != 0 || bytes != 0 {
		t.Fatalf("verify found %d files / %d bytes after a successful retry", files, bytes)
	}
}

func TestSweepRemovesTreesLeftByAKilledProcess(t *testing.T) {
	work := t.TempDir()

	write(t, filepath.Join(TempRoot(work), "job-old-1", "audio.wav"), strings.Repeat("a", 300))
	write(t, filepath.Join(TempRoot(work), "job-old-2", "parts", "audio.opus.part"), strings.Repeat("p", 100))
	keep := filepath.Join(work, "models", "parakeet", "encoder.int8.onnx")
	write(t, keep, "a model is not media")

	removed, bytes, failed := SweepOrphaned(work)
	if failed != 0 {
		t.Fatalf("sweep reported %d failures", failed)
	}
	if removed != 2 {
		t.Fatalf("sweep removed %d trees, want 2", removed)
	}
	if bytes != 400 {
		t.Fatalf("sweep freed %d bytes, want 400", bytes)
	}
	if _, err := os.Stat(TempRoot(work)); !os.IsNotExist(err) {
		t.Fatal("the temp root survived the sweep")
	}
	if _, err := os.Stat(keep); err != nil {
		t.Fatalf("the sweep touched something outside the temp root: %v", err)
	}

	again, againBytes, againFailed := SweepOrphaned(work)
	if again != 0 || againBytes != 0 || againFailed != 0 {
		t.Fatalf("a second sweep found work to do: %d/%d/%d", again, againBytes, againFailed)
	}

	missing, missingBytes, missingFailed := SweepOrphaned(filepath.Join(work, "does-not-exist"))
	if missing != 0 || missingBytes != 0 || missingFailed != 0 {
		t.Fatalf("a sweep with no temp root should be a no-op: %d/%d/%d", missing, missingBytes, missingFailed)
	}
}

func TestWorkspaceIsWhatTheStepSaw(t *testing.T) {
	work := t.TempDir()

	workspace, err := NewWorkspace(work, "job-files")
	if err != nil {
		t.Fatalf("NewWorkspace: %v", err)
	}
	audio, _ := workspace.File("audio.wav")
	write(t, audio, strings.Repeat("x", 42))
	if files, bytes := Verify(work); files != 1 || bytes != 42 {
		t.Fatalf("verify = %d files / %d bytes, want 1 / 42", files, bytes)
	}

	if err := os.MkdirAll(TempRoot(work), 0755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if files, _ := Verify(work); files != 1 {
		t.Fatalf("verify counted %d files, want 1", files)
	}
}
