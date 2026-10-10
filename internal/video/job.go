package video

import (
	"context"
	"errors"
	"fmt"
	"runtime/debug"
	"sort"
	"strings"
	"sync"
	"time"

	"localsy/internal/util"
)

// Stage is where a watch is right now. The web layer renders these as the spinner's text.
type Stage string

const (
	StageQueued     Stage = "queued"
	StageSources    Stage = "sources"
	StageAudio      Stage = "audio"
	StageTranscribe Stage = "transcribe"
	StageDone       Stage = "done"
	StageFailed     Stage = "failed"
	StageCancelled  Stage = "cancelled"
)

type Segment struct {
	Start float64 `json:"start"`
	End   float64 `json:"end"`
	Text  string  `json:"text"`
}

type Chapter struct {
	Title string  `json:"title"`
	Start float64 `json:"start"`
	End   float64 `json:"end"`
}

type Metadata struct {
	Title    string    `json:"title,omitempty"`
	Channel  string    `json:"channel,omitempty"`
	Language string    `json:"language,omitempty"`
	Duration float64   `json:"duration,omitempty"`
	Tags     []string  `json:"tags,omitempty"`
	Chapters []Chapter `json:"chapters,omitempty"`
}

// Result is what survives a watch: text, timings and provenance. Never media.
type Result struct {
	Metadata    Metadata  `json:"metadata"`
	Manual      []Segment `json:"manual,omitempty"`
	Auto        []Segment `json:"auto,omitempty"`
	ASR         []Segment `json:"asr,omitempty"`
	Outcome     string    `json:"outcome"`
	Notice      string    `json:"notice,omitempty"`
	Captions    []Segment `json:"captions,omitempty"`
	CaptionKind string    `json:"caption_kind,omitempty"`
	// CaptionSource names the client that supplied the caption track ("browser" or "downloader"). Reported
	// rather than assumed: the two routes fail differently, and a watch whose captions came from the
	// downloader proves nothing about the browser route being wired.
	CaptionSource string `json:"caption_source,omitempty"`
}

// Worker runs Stage A and Stage B. It exists so the job's guarantees — cancellation, panic
// containment, media deletion on every exit path — are testable without yt-dlp, ffmpeg or a model
// on the machine.
//
// A Worker must run its child processes synchronously in this goroutine. A panic in a goroutine the
// Worker spawns is not recoverable by the job and takes the launcher down; use Child.Run, which
// already handles cancellation and the process tree.
type Worker interface {
	Run(ctx context.Context, job *Job, ws *Workspace) (*Result, error)
}

// Job is one watch. Every mutable field is guarded: PHP polls a job while it runs.
type Job struct {
	ID  string
	URL string

	mu         sync.Mutex
	stage      Stage
	status     string
	step       int
	total      int
	errText    string
	stack      string
	started    time.Time
	finished   time.Time
	result     *Result
	mediaFiles int
	mediaBytes int64
	mediaLeft  int
	mediaErr   string

	ws     *Workspace
	cancel context.CancelFunc
	done   chan struct{}
}

// Snapshot is the job as the web layer sees it: a copy, so a poll can never observe a torn state.
type Snapshot struct {
	ID         string  `json:"id"`
	URL        string  `json:"url"`
	Stage      Stage   `json:"stage"`
	Status     string  `json:"status"`
	Step       int     `json:"step,omitempty"`
	Total      int     `json:"total,omitempty"`
	Error      string  `json:"error,omitempty"`
	Stack      string  `json:"stack,omitempty"`
	ElapsedMS  int64   `json:"elapsed_ms"`
	MediaFiles int     `json:"media_files"`
	MediaBytes int64   `json:"media_bytes"`
	MediaLeft  int     `json:"media_left"`
	MediaError string  `json:"media_error,omitempty"`
	Result     *Result `json:"result,omitempty"`
	Done       bool    `json:"done"`
}

// Manager owns the running jobs. One workspace per job, cancelled by id, reaped by the job itself.
type Manager struct {
	workDir string
	worker  Worker

	mu   sync.Mutex
	jobs map[string]*Job
	seq  int
}

func NewManager(workDir string, worker Worker) *Manager {
	return &Manager{workDir: workDir, worker: worker, jobs: map[string]*Job{}}
}

// keepFinishedJobs is how many finished watches stay pollable. The web layer reads a job within seconds
// of starting it; this cap exists only so a tray app that runs for weeks does not accumulate a
// transcript per watch in memory.
const keepFinishedJobs = 20

func (m *Manager) Start(url string) (*Job, error) {
	url = strings.TrimSpace(url)
	if url == "" {
		return nil, errors.New("video: no url")
	}
	if m.worker == nil {
		return nil, errors.New("video: no transcription backend configured")
	}

	m.mu.Lock()
	m.seq++
	job := &Job{
		ID:      fmt.Sprintf("w%d-%d", time.Now().UnixNano()/1e6, m.seq),
		URL:     url,
		stage:   StageQueued,
		status:  "Queued",
		started: time.Now(),
		done:    make(chan struct{}),
	}
	m.jobs[job.ID] = job
	m.trimLocked()
	m.mu.Unlock()

	ctx, cancel := context.WithCancel(context.Background())
	job.mu.Lock()
	job.cancel = cancel
	job.mu.Unlock()

	go m.run(ctx, job)

	return job, nil
}

// trimLocked drops the oldest finished watches past the cap. Called with m.mu held, and finished-ness is
// read from the job's own channel rather than its fields, so this cannot race the job's writer.
func (m *Manager) trimLocked() {
	if len(m.jobs) <= keepFinishedJobs {
		return
	}

	finished := make([]*Job, 0, len(m.jobs))
	for _, job := range m.jobs {
		select {
		case <-job.done:
			finished = append(finished, job)
		default:
		}
	}
	if len(finished) <= keepFinishedJobs {
		return
	}

	sort.Slice(finished, func(i, j int) bool { return finished[i].started.Before(finished[j].started) })
	for _, job := range finished[:len(finished)-keepFinishedJobs] {
		delete(m.jobs, job.ID)
	}
}

func (m *Manager) Get(id string) (*Job, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	job, ok := m.jobs[id]

	return job, ok
}

// Cancel stops a running job. It is the stop button's whole server side: the call returns
// immediately with the job marked cancelled, the child process tree is killed, and the job's own
// exit path deletes the media.
func (m *Manager) Cancel(id string) bool {
	m.mu.Lock()
	job, ok := m.jobs[id]
	m.mu.Unlock()
	if !ok {
		return false
	}

	return job.requestCancel()
}

func (m *Manager) run(ctx context.Context, job *Job) {
	defer close(job.done)

	ws, err := NewWorkspace(m.workDir, job.ID)
	if err != nil {
		job.fail("cannot create a workspace for this job", err.Error(), "")
		return
	}
	job.mu.Lock()
	job.ws = ws
	job.mu.Unlock()

	// Registered first, so it runs last: after a panic has been turned into a failed job, after a
	// cancellation, after a success. This is the media guarantee, and its outcome is recorded on the
	// job rather than assumed.
	defer func() {
		job.recordMedia(ws.Cleanup())
	}()

	// Registered second, so it runs first: a panic anywhere in the worker becomes a failed job with
	// its stack, instead of a dead launcher and a dead tray icon.
	defer func() {
		if rec := recover(); rec != nil {
			stack := string(debug.Stack())
			job.fail("The watch crashed", fmt.Sprintf("panic: %v", rec), stack)
			util.LogPrint("[-] watch %s: panic in the job goroutine, job failed:\n%s\n", job.ID, stack)
		}
	}()

	result, err := m.worker.Run(ctx, job, ws)
	if err != nil {
		if ctx.Err() != nil {
			job.markCancelled()
			util.LogPrint("[!] watch %s: cancelled by the user\n", job.ID)
			return
		}
		job.fail("the watch failed", err.Error(), "")
		return
	}

	job.succeed(result)
	util.LogPrint("[+] watch %s: transcript ready (%d manual, %d auto, %d asr segments)\n",
		job.ID, len(result.Manual), len(result.Auto), len(result.ASR))
}

// The worker calls these to publish progress; they are the only writers of the job's state.

func (j *Job) SetStage(stage Stage, status string) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.finishedLocked() {
		return
	}
	j.stage = stage
	j.status = status
	j.step, j.total = 0, 0
}

func (j *Job) Progress(stage Stage, status string, step, total int) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.finishedLocked() {
		return
	}
	j.stage = stage
	j.status = status
	j.step, j.total = step, total
}

func (j *Job) Snapshot() Snapshot {
	j.mu.Lock()
	defer j.mu.Unlock()

	snap := Snapshot{
		ID:         j.ID,
		URL:        j.URL,
		Stage:      j.stage,
		Status:     j.status,
		Step:       j.step,
		Total:      j.total,
		Error:      j.errText,
		Stack:      j.stack,
		MediaFiles: j.mediaFiles,
		MediaBytes: j.mediaBytes,
		MediaLeft:  j.mediaLeft,
		MediaError: j.mediaErr,
		Result:     j.result,
		Done:       j.finishedLocked(),
	}
	end := time.Now()
	if !j.finished.IsZero() {
		end = j.finished
	}
	snap.ElapsedMS = end.Sub(j.started).Milliseconds()

	return snap
}

// Done is closed when the job has stopped for any reason. The media is already deleted when it is.
func (j *Job) Done() <-chan struct{} { return j.done }

// Workspace exposes the job's directory to its worker.
func (j *Job) Workspace() *Workspace {
	j.mu.Lock()
	defer j.mu.Unlock()

	return j.ws
}

func (j *Job) requestCancel() bool {
	j.mu.Lock()
	if j.finishedLocked() || j.cancel == nil {
		j.mu.Unlock()
		return false
	}
	cancel := j.cancel
	j.stage = StageCancelled
	j.status = "Cancelling…"
	j.mu.Unlock()

	cancel() // kills the child process tree via the child runner's watcher

	return true
}

func (j *Job) markCancelled() {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.stage = StageCancelled
	j.status = "Cancelled"
	j.step, j.total = 0, 0
	j.finished = time.Now()
}

func (j *Job) succeed(result *Result) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.finishedLocked() {
		return
	}
	j.result = result
	j.stage = StageDone
	j.status = "Transcript ready"
	j.finished = time.Now()
}

func (j *Job) fail(status, errText, stack string) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.finishedLocked() {
		return
	}
	j.stage = StageFailed
	j.status = status
	j.errText = errText
	j.stack = stack
	j.finished = time.Now()
}

func (j *Job) recordMedia(report Report) {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.mediaFiles = report.Files
	j.mediaBytes = report.Bytes
	j.mediaLeft = report.LeftFiles + report.LeftDirs
	j.mediaErr = report.Error
}

func (j *Job) finishedLocked() bool { return !j.finished.IsZero() }
