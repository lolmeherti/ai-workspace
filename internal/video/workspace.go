// Package video owns the watch job's temporary media and the guarantee that none of it
// survives the job (`.hermes/plans/ui/youtube-watcher.md`, locked decision 16).
//
// Every byte yt-dlp and ffmpeg produce goes inside one workspace directory. The job deletes
// the whole tree — on success, on error and on cancellation — and a tree that survives a hard
// kill is removed by the boot sweep. Nothing here writes media anywhere else, and nothing
// returns media: the job's answer to the caller is text plus timings.
package video

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"localsy/internal/util"
)

const (
	tmpDirName     = "tmp"
	watchDirName   = "watch"
	cleanupRetries = 4
	retryDelay     = 150 * time.Millisecond
	maxJobIDLen    = 64
)

// Workspace is the only directory a watch job may put media in. remove is a seam for tests: a real
// remover cannot be made to fail on demand, and the failure path is the one that must not be a shrug.
type Workspace struct {
	root   string
	jobID  string
	remove func(string) error
}

// Report is what a deletion actually did, so the caller reports facts instead of assuming them.
type Report struct {
	Root      string
	JobID     string
	Files     int
	Bytes     int64
	LeftFiles int
	LeftDirs  int
	LeftBytes int64
	Failed    bool
	Attempts  int
	Error     string
}

// TempRoot is the single directory watch media is allowed to live in.
func TempRoot(workDir string) string {
	return filepath.Join(workDir, tmpDirName, watchDirName)
}

// NewWorkspace creates the job's directory. The job id is sanitised first: it arrives from an HTTP
// request and must never be able to place media outside the temp root.
func NewWorkspace(workDir, jobID string) (*Workspace, error) {
	id := sanitiseJobID(jobID)
	if id == "" {
		return nil, errors.New("video: job id is empty after sanitising")
	}

	root := filepath.Join(TempRoot(workDir), id)
	if err := os.MkdirAll(root, 0755); err != nil {
		return nil, fmt.Errorf("video: cannot create workspace: %w", err)
	}

	return &Workspace{root: root, jobID: id, remove: os.RemoveAll}, nil
}

func (w *Workspace) Root() string  { return w.root }
func (w *Workspace) JobID() string { return w.jobID }

// File resolves a media file name inside the workspace. A name that could escape it is refused
// rather than cleaned up silently — media outside this tree is the one thing that must not happen.
func (w *Workspace) File(name string) (string, error) {
	if name == "" {
		return "", errors.New("video: file name is empty")
	}
	if filepath.IsAbs(name) || strings.ContainsAny(name, `/\`) || strings.HasPrefix(name, ".") {
		return "", fmt.Errorf("video: refusing file name %q", name)
	}

	return filepath.Join(w.root, name), nil
}

// Cleanup deletes the tree, retrying briefly: on Windows a child process that is still exiting
// holds the handle for a moment. Failed=true means media is still on the drive and says so.
func (w *Workspace) Cleanup() Report {
	report := Report{Root: w.root, JobID: w.jobID}
	report.Files, report.Bytes = measure(w.root)

	started := time.Now()
	for attempt := 1; attempt <= cleanupRetries; attempt++ {
		report.Attempts = attempt

		err := w.remove(w.root)
		leftFiles, leftDirs, leftBytes := residue(w.root)
		report.LeftFiles, report.LeftDirs, report.LeftBytes = leftFiles, leftDirs, leftBytes

		if err == nil && leftFiles == 0 && leftDirs == 0 {
			report.Failed = false
			report.Error = ""
			removeIfEmpty(filepath.Dir(w.root))
			util.LogPrint("[+] watch %s: media deleted (%d files, %d bytes) in %dms\n",
				w.jobID, report.Files, report.Bytes, time.Since(started).Milliseconds())

			return report
		}

		report.Failed = true
		if err != nil {
			report.Error = err.Error()
		}
		time.Sleep(retryDelay * time.Duration(attempt))
	}

	util.LogPrint("[-] watch %s: media deletion FAILED after %d attempts — leftover %d files / %d dirs / %d bytes at %s: %s\n",
		w.jobID, report.Attempts, report.LeftFiles, report.LeftDirs, report.LeftBytes, w.root, report.Error)

	return report
}

// SweepOrphaned removes watch trees left behind by a killed process. A defer does not run on a hard
// kill, so this is what keeps the rule true rather than aspirational. Anything under the temp root
// at boot is orphaned by definition: no job can be running yet.
func SweepOrphaned(workDir string) (removed int, bytes int64, failed int) {
	root := TempRoot(workDir)

	entries, err := os.ReadDir(root)
	if err != nil {
		return 0, 0, 0
	}

	for _, entry := range entries {
		path := filepath.Join(root, entry.Name())
		_, size := measure(path)

		if err := os.RemoveAll(path); err != nil {
			failed++
			util.LogPrint("[-] watch sweep: could not remove %s: %v\n", path, err)
			continue
		}
		if left, _, _ := residue(path); left > 0 {
			failed++
			util.LogPrint("[-] watch sweep: %d files still in %s after removal\n", left, path)
			continue
		}

		removed++
		bytes += size
	}

	removeIfEmpty(root)

	if removed > 0 || failed > 0 {
		util.LogPrint("[!] watch sweep: removed %d leftover workspace(s), %d bytes, %d failed\n", removed, bytes, failed)
	}

	return removed, bytes, failed
}

// Verify reports what is still sitting in the temp root. The check after a watch is that this is zero.
func Verify(workDir string) (files int, bytes int64) {
	if _, err := os.Stat(TempRoot(workDir)); err != nil {
		return 0, 0
	}

	return measure(TempRoot(workDir))
}

func measure(path string) (files int, bytes int64) {
	if _, err := os.Stat(path); err != nil {
		return 0, 0
	}

	_ = filepath.WalkDir(path, func(_ string, entry fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if entry.IsDir() {
			return nil
		}

		files++
		if info, statErr := entry.Info(); statErr == nil {
			bytes += info.Size()
		}

		return nil
	})

	return files, bytes
}

// residue is what a deletion attempt left behind: leftover directories count too, because a directory
// that cannot be removed means a child process still holds it — the media is going nowhere yet.
func residue(path string) (files int, dirs int, bytes int64) {
	if _, err := os.Stat(path); err != nil {
		return 0, 0, 0
	}

	_ = filepath.WalkDir(path, func(current string, entry fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if entry.IsDir() {
			if current != path {
				dirs++
			}
			return nil
		}

		files++
		if info, statErr := entry.Info(); statErr == nil {
			bytes += info.Size()
		}

		return nil
	})

	if _, err := os.Stat(path); err == nil && files == 0 && dirs == 0 {
		dirs = 1
	}

	return files, dirs, bytes
}

// removeIfEmpty drops a directory once nothing is left in it, so a completed watch leaves no trail of
// empty directories behind — "absent or empty" is the check the plan states, and this is what makes it
// literally true.
func removeIfEmpty(path string) {
	entries, err := os.ReadDir(path)
	if err != nil || len(entries) > 0 {
		return
	}

	_ = os.Remove(path)
}

func sanitiseJobID(jobID string) string {
	var builder strings.Builder
	for _, char := range jobID {
		switch {
		case char >= 'a' && char <= 'z', char >= 'A' && char <= 'Z', char >= '0' && char <= '9':
			builder.WriteRune(char)
		case char == '-' || char == '_':
			builder.WriteRune(char)
		}
		if builder.Len() >= maxJobIDLen {
			break
		}
	}

	return builder.String()
}
