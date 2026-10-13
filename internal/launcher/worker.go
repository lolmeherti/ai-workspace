package launcher

import (
	"errors"
	"os/exec"
	"time"

	"localsy/internal/util"
)

// Every turn is a queue item now, so the queue needs a consumer that is simply there. A worker the app spawns per turn
// has to fork PHP and boot the framework before it can answer anything, and a job that gets handed back (busy engine, or
// deferred to a live turn) has nobody guaranteed to pick it up at all until somebody starts a worker. This supervisor is
// that guarantee, and it is deliberately dull: every few seconds, ask the container whether a resident worker is running,
// and start one if it is not. The app's own PipelineWorker::spawn() stays as the fallback for the window before this has
// run, and it logs when it fires.
const (
	workerContainer  = "ai_php_web"
	workerPath       = "/var/www/html/pipeline-worker.php"
	workerCheckEvery = 5 * time.Second
)

// StartWorkerSupervisor keeps one resident worker alive inside the app container. It returns immediately; the watching
// happens in the background for the life of the launcher, which is also the worker's supervisor — if the container is
// restarted, the next check starts the worker again.
func StartWorkerSupervisor() {
	go func() {
		couldNotCheck := false
		for {
			running, answered := workerState()
			switch {
			case running:
				// Somebody is home: nothing to do.
			case answered:
				startWorker()
			case !couldNotCheck:
				// The container is not up yet, or docker is still coming up. Say it once rather than every five seconds,
				// and keep quiet until the question can actually be answered again.
				util.LogPrint("[.] worker: the app container cannot be asked yet — will keep trying\n")
				couldNotCheck = true
			}
			if answered {
				couldNotCheck = false
			}
			time.Sleep(workerCheckEvery)
		}
	}()
}

// workerState asks the container whether a resident worker process exists, AND whether the question could be answered at
// all. The two are deliberately separate: starting a worker when we merely could not look would pile them up.
func workerState() (running bool, answered bool) {
	cmd := util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root",
		"docker", "exec", workerContainer, "pgrep", "-f", "pipeline-worker.php --resident")

	err := cmd.Run()
	if err == nil {
		return true, true // pgrep found a match
	}

	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) && exitErr.ExitCode() == 1 {
		return false, true // pgrep answered: no such process
	}

	return false, false // container down, or pgrep could not be run at all
}

// startWorker starts the worker detached. The worker writes its own log (logs/pipeline-worker.log) and its events reach
// app_events, so nothing here needs its output.
func startWorker() {
	cmd := util.RunSilentCommand("wsl", "-d", "localsy-docker-backend", "-u", "root",
		"docker", "exec", "-d", workerContainer, "php", workerPath, "--resident")
	if err := cmd.Run(); err != nil {
		util.LogPrint("[!] worker: could not start the resident queue worker: %v\n", err)

		return
	}
	util.LogPrint("[+] worker: resident queue worker started in %s\n", workerContainer)
}
