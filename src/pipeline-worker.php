<?php

declare(strict_types=1);

/*
 * The pipeline worker: the process that does the app's own work, outside any request.
 *
 *   php pipeline-worker.php                 # drain the queue, then exit once it has been idle for a while
 *   php pipeline-worker.php --once          # run at most one job (used by the probe and by hand)
 *   php pipeline-worker.php --idle-exit=5000 --grace=0 --name=test-worker
 *
 * Why a separate process: the owner's rule is that enrichment and the answering of queued messages must finish even if
 * the browser is closed. A request-scoped pass cannot promise that (measured: a spawned child survives both a normal
 * request and a client that walks away — src/tests/live/spawn-detach-probe.php). So the request only *spawns* this and
 * never waits on it.
 *
 * What it does, per job:
 *   1. take the inference lock — if somebody else holds it, hand the job straight back (no attempt counted) and stop:
 *      the rows stay raw and the turn-start backstop will reclaim them, so a busy engine is never a data loss;
 *   2. run the job body (enrich a turn's fetch rows, or answer a batch of held messages);
 *   3. complete it, or fail it (the queue decides whether that means a retry).
 *
 * See `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\AgentManager;
use App\ChatManager;
use App\Config;
use App\Database;
use App\HealthCheck;
use App\Logger;
use App\Services\ActiveTurns;
use App\Services\JobQueue;
use App\Services\ModelLock;
use App\Services\PendingPromptQueue;
use App\Services\PipelineWorker;
use App\Services\QueueReconciliation;
use App\Services\TurnStream;

Config::load(__DIR__);

$opts = getopt('', ['once', 'resident', 'idle-exit::', 'name::', 'busy-patience::']);
$once = array_key_exists('once', $opts);
$resident = array_key_exists('resident', $opts);
// A resident worker is supervised — the launcher starts it and restarts it if it dies — so it never leaves on its own.
// The idle and patience windows exist for a SPAWNED worker (one per turn, started by the app), which must eventually
// stop. The supervised worker has to still be there when the next job lands: without it, a job handed back waits for
// whoever happens to start a worker next, and a worker that starts inside a live turn's 20s window exits without
// claiming anything at all (both measured).
$idleExitMs = $resident ? PHP_INT_MAX : (int)($opts['idle-exit'] ?? 15_000);
$workerName = (string)($opts['name'] ?? ('worker-' . getmypid()));
// How long this process waits for a busy engine before leaving the job for the next worker. A released job has nobody
// guaranteed to pick it up: the worker that put it back used to exit, so the job sat pending and the composer kept
// saying "your message is queued" with no answer coming (measured). Bounded, so a wedged engine cannot pin a process —
// except for the resident worker, which is the thing that should still be waiting.
$busyPatienceMs = $resident ? PHP_INT_MAX : (int)($opts['busy-patience'] ?? 600_000);
$busyRetryUs = 2_000_000;

$logFile = __DIR__ . '/logs/pipeline-worker.log';

/** One line to the worker's own log and to stdout (the spawn redirects stdout into the same directory). */
$log = static function (string $level, string $message, array $context = []) use ($logFile, $workerName): void {
    $line = sprintf("[%s] %s %s %s\n", gmdate('c'), strtoupper($level), $workerName, $message
        . ($context !== [] ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
};

/**
 * One question, asked of the engine: are you there? `HealthCheck` memoises its answer for five seconds (the probe is a
 * real request to the inference engine, and the worker loop asks on every iteration); `true` insists on a current answer,
 * which is what deciding "was that failure the engine's or the work's" needs.
 */
$engineAnswers = static function (bool $force = false): bool {
    try {
        return (bool)((new HealthCheck())->checkAi($force)['online'] ?? false);
    } catch (\Throwable $e) {
        return false;
    }
};

try {
    $db = new Database();
    $db->initTables();
    // Without this the worker's events (job_claimed, enrichment_job_*, consolidation_*) reach only the file log and
    // never app_events — invisible to /logs and to every per-session view. A CLI entry point must initialise the
    // logger itself; index.php does it for web requests.
    Logger::setDatabase($db);
    $queue = new JobQueue();
    $manager = new ChatManager($db, new AgentManager());
} catch (\Throwable $e) {
    $log('error', 'worker could not start: ' . $e->getMessage());
    exit(1);
}

if (!$once) {
    $log('info', 'starting', $resident ? ['mode' => 'resident'] : ['idle_exit_ms' => $idleExitMs]);
}

// Boot is what reclaims: the database is the truth, so the queue is made to say exactly that before anything is
// consumed. Only the resident worker does it — a spawned worker starts mid-life, where another worker may hold a live
// claim that must not be taken from it. No timers in here either: a claim is checked against its owner's process, not
// against its age.
if ($resident) {
    try {
        $log('info', 'reconciling the queue against the database', (new QueueReconciliation($db, $queue))->run());
    } catch (\Throwable $e) {
        $log('error', 'queue reconciliation failed: ' . $e->getMessage());
    }
}

$deadline = microtime(true) + $idleExitMs / 1000;
$busyUntil = microtime(true) + $busyPatienceMs / 1000;
$ran = 0;
$engineDown = false;

while (true) {
    // A lock whose holder process is gone is not a lock. Checking here (two reads) means a worker that starts after a
    // killed one frees the engine immediately instead of refusing every turn until the TTL runs out — which is what a
    // quarter-hour of "AI is busy with another task" looked like (R11: an outage is never charged to the queue).
    ModelLock::reclaimDeadOwner();

    // The current loop outranks every lane (owner's ruling, 2026-10-11). While a turn is live this process claims
    // nothing: enrichment started here would take the inference lock between that turn's tool round and its answer and
    // stall a turn the user is waiting on ("the ai cant do work because the atomization is pending"), and answering is
    // supposed to wait for the turn to end anyway. The lane order below then does the rest: p1 (atomization) before p2.
    if (ActiveTurns::any()) {
        if ($once || microtime(true) >= $deadline) {
            $log('info', 'a turn is live — leaving the queue alone', ['active' => ActiveTurns::sessions()]);
            break;
        }
        usleep($busyRetryUs);
        continue;
    }

    // Consume only when the engine answers (R11). A queue drained while the engine is down spends attempts on an outage:
    // the work cannot be run, the failure belongs to the infrastructure, and three such moments would kill a job for
    // good. Nothing is claimed, nothing is counted, nothing fails — the queue waits, which is what a queue is for.
    if (!$engineAnswers()) {
        if (!$engineDown) {
            $log('info', 'engine not answering — holding the queue', ['pending' => $queue->counts()['pending']]);
            $engineDown = true;
        }
        if ($once || microtime(true) >= $deadline) {
            break;
        }
        usleep(2_000_000);
        continue;
    }
    if ($engineDown) {
        $log('info', 'engine is answering again — resuming the queue');
        $engineDown = false;
    }

    $job = $queue->claim($workerName);
    if ($job === null) {
        if ($once || microtime(true) >= $deadline) {
            break;
        }
        // 100 ms, not 500: every turn a resident worker picks up used to wait up to half a second just to be noticed, which
        // is exactly the "slightly sluggish" feel of sending a message. The poll is one cheap Redis read.
        usleep(100_000);
        continue;
    }

    $deadline = microtime(true) + $idleExitMs / 1000;
    $busyUntil = microtime(true) + $busyPatienceMs / 1000;
    $jobId = (string)($job['id'] ?? '');
    $type = (string)($job['type'] ?? '');
    $sessionId = (int)($job['session_id'] ?? 0);

    // Step 2 before step 3 for the conversation it belongs to: answering queued messages waits for that
    // conversation's atomization, because answering from a store whose notes are not built yet is what the queue
    // exists to avoid. The job is handed back untouched (no attempt counted) so it runs the moment p1 is clear.
    if ($type === JobQueue::TYPE_ANSWER && $queue->laneBusyForSession($sessionId, 'p1')) {
        $queue->release($jobId);
        Logger::logEvent('answer_deferred_for_atomization', 'Answer job waiting for this conversation’s atomization', [
            'job_id' => $jobId, 'session_id' => $sessionId,
        ], 'info', 'pipeline-worker');
        $log('info', 'deferred: atomization is ahead of this conversation', ['job_id' => $jobId]);
        if ($once || microtime(true) >= $busyUntil) {
            break;
        }
        usleep($busyRetryUs);
        continue;
    }

    // Scope this job's events to its conversation, so the worker's work shows up in that session's timeline at /logs
    // rather than as session-less noise (the same trap the live harness hit: events without a session id are invisible
    // to every per-session view).
    Logger::setSessionId($sessionId > 0 ? $sessionId : null);

    // An answer job is NOT run under the worker's own lock: it runs a whole turn, and the turn takes the inference lock
    // itself (that is also how a busy engine is detected and reported). Only enrichment work is done inside the lock.
    $ownsLock = ($type === JobQueue::TYPE_ENRICH);
    $lockToken = null;
    if ($ownsLock) {
        $lockToken = ModelLock::acquire(ModelLock::PROCESS_TTL_MS);
        if ($lockToken === null) {
            // Somebody else has the engine. Put the job down untouched: the rows stay raw and the turn-start backstop
            // reclaims them, so a busy engine never costs data (plan R11).
            $queue->release($jobId);
            $log('warn', 'inference lock is held by someone else — job handed back', ['job_id' => $jobId, 'type' => $type]);
            Logger::logEvent('enrichment_skipped', 'Worker found the inference lock taken', [
                'job_id' => $jobId,
                'type' => $type,
                'session_id' => $sessionId,
                'held_by' => ModelLock::busyMessage(),
            ], 'warn', 'pipeline-worker');
            if ($once || microtime(true) >= $busyUntil) {
                $log('warn', 'engine still busy — leaving the job pending for the next worker', ['job_id' => $jobId]);
                break;
            }
            usleep($busyRetryUs);
            continue;
        }

        ModelLock::setStatus($lockToken, 'Indexing the source you just handed over — notes and contents index');
    }

    $started = microtime(true);

    // Every turn publishes its own output — typed, indexed, in order — to the turn's stream, so any browser can watch
    // it: the one that submitted, another tab, or a client that reconnects after the fact. Publishing is transport: a
    // failure here is logged and never fails the turn (ruling R18).
    $turnStream = new TurnStream();
    $publish = static function (string $event, array $data = []) use ($turnStream, $sessionId, $jobId): void {
        try {
            $turnStream->publish($sessionId, $event, $data);
            if ($event === 'done') {
                $turnStream->finish($sessionId);
            }
        } catch (\Throwable $e) {
            Logger::logEvent('turn_stream_publish_failed', 'Could not publish a turn event: ' . $e->getMessage(), [
                'job_id' => $jobId,
                'session_id' => $sessionId,
                'event' => $event,
            ], 'warn', 'pipeline-worker');
        }
    };

    try {
        if ($type === JobQueue::TYPE_TURN) {
            // A whole turn: the request wrote the user row and queued it; this worker runs the loop and adopts the row
            // (ruling R1). The row's own words are the query — the job carries an id, not a copy of the message.
            //
            // The per-submit choices (reasoning effort, the editor's file, the link decision) are NOT passed here either:
            // they are columns on that row, and the manager resolves them when the turn starts
            // (ChatManager::resolveTurnChoices()). Passing copies is what let a choice get lost — every ordinary send
            // arrived with `fetch_urls: []`, which means "the user turned links off", so the link scan never ran. See
            // .hermes/plans/tools/2026-10-12_tool-lifecycle-and-queue-integrity.md.
            $userRowId = (int)($job['payload']['user_row_id'] ?? 0);
            $rows = $db->query(
                'SELECT message FROM chat_history WHERE id = ? AND session_id = ?',
                [$userRowId, $sessionId]
            );

            if ($rows === []) {
                Logger::logEvent('turn_job_no_row', 'Turn job found no row to answer', [
                    'job_id' => $jobId, 'session_id' => $sessionId, 'user_row_id' => $userRowId,
                ], 'warn', 'pipeline-worker');
                $log('warn', 'turn job found no row', ['job_id' => $jobId, 'row_id' => $userRowId]);
            } else {
                // A fresh turn's stream starts empty: the previous turn's frames are gone (its answer is a row), so a
                // viewer can never be handed a stale chunk as if it were this turn's.
                $turnStream->reset($sessionId);
                Logger::logEvent('turn_job_started', 'Turn job started', [
                    'job_id' => $jobId, 'session_id' => $sessionId, 'user_row_id' => $userRowId,
                ], 'info', 'pipeline-worker');

                try {
                    $manager->process($sessionId, (string)$rows[0]['message'], null, null, null, $publish, null, [$userRowId]);
                } catch (\Throwable $e) {
                    // Somebody is watching this stream: say what broke and end it, so the bubble reports the failure
                    // instead of sitting there until its own deadline. The throw carries on to the queue's failure path.
                    $publish('error', [
                        'code' => 'internal_error',
                        'message' => 'The turn failed: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . '. Details in /logs.',
                    ]);
                    $publish('done', ['message' => '', 'session_id' => $sessionId]);
                    throw $e;
                }

                Logger::logEvent('turn_job_finished', 'Turn job finished', [
                    'job_id' => $jobId, 'session_id' => $sessionId, 'user_row_id' => $userRowId,
                ], 'info', 'pipeline-worker');

                // The app's own work starts when the turn ends: whatever this turn fetched gets its facts and contents
                // index as a queued job, run by a detached worker — never inside the turn itself (plan R1/R2). Enqueued
                // only when the turn actually fetched something, so an ordinary turn starts no process. A failure here
                // cannot affect the answer the user already has: the rows stay raw and the backstop reclaims them (R11).
                try {
                    $fetchRows = $manager->turnFetchRowIds();
                    $enrichJobId = PipelineWorker::enqueueEnrichment($sessionId, $fetchRows);
                    if ($enrichJobId !== null) {
                        $publish('trace', ['label' => sprintf('App: added to the queue — atomization of %d stored passage%s (rows %s)',
                            count($fetchRows), count($fetchRows) === 1 ? '' : 's', implode(', ', $fetchRows))]);
                    }
                } catch (\Throwable $e) {
                    Logger::logEvent('worker_enqueue_failed', 'Post-turn enqueue failed: ' . $e->getMessage(), [
                        'session_id' => $sessionId,
                        'at' => $e->getFile() . ':' . $e->getLine(),
                    ], 'error', 'pipeline-worker');
                }
            }
        } elseif ($type === JobQueue::TYPE_ENRICH) {
            $rowIds = array_map('intval', (array)($job['payload']['row_ids'] ?? []));
            Logger::logEvent('enrichment_job_started', 'Enrichment job started', [
                'job_id' => $jobId, 'session_id' => $sessionId, 'rows' => count($rowIds),
            ], 'info', 'pipeline-worker');

            $summary = $manager->enrichRows($sessionId, $rowIds);

            Logger::logEvent('enrichment_job_finished', 'Enrichment job finished', [
                'job_id' => $jobId,
                'session_id' => $sessionId,
                'rows' => $summary['rows'],
                'enriched' => $summary['enriched'],
                'empty' => $summary['empty'],
                'failed' => $summary['failed'],
                'skipped' => $summary['skipped'],
                'atoms' => $summary['atoms'],
                'reclaimed' => $summary['reclaimed'],
                'ms' => $summary['ms'],
            ], $summary['failed'] > 0 ? 'warn' : 'info', 'pipeline-worker');

            $log('info', "enriched session {$sessionId}", $summary);
        } elseif ($type === JobQueue::TYPE_ANSWER) {
            // The batch nobody streamed: the messages were sent while the engine was busy and no browser stayed to watch.
            // Take them (draining is atomic), answer them through the normal turn path, and let the answer be stored for
            // whatever browser comes back (plan R10).
            $held = new PendingPromptQueue();
            $entries = $held->drain($sessionId);
            if ($entries === []) {
                Logger::logEvent('worker_answer_empty', 'Answer job found nothing held', [
                    'job_id' => $jobId, 'session_id' => $sessionId,
                ], 'info', 'pipeline-worker');
                $log('info', 'answer job found nothing held', ['job_id' => $jobId]);
            } else {
                $batchText = PendingPromptQueue::joinText($entries);
                $rowIds = array_map(static fn(array $entry): int => (int)$entry['row_id'], $entries);
                Logger::logEvent('worker_answering_batch', 'Answering held messages with no client attached', [
                    'job_id' => $jobId, 'session_id' => $sessionId, 'rows' => $rowIds, 'chars' => mb_strlen($batchText),
                ], 'info', 'pipeline-worker');

                try {
                    $manager->process($sessionId, $batchText, null, null, null, $publish, null, $rowIds);
                } catch (App\Services\ModelBusyException $e) {
                    // The engine was taken again (a browser arrived, or another turn started). Hand the batch back in
                    // one round trip: the entries keep their arrival order and land in front of anything appended
                    // since the take, so nothing is answered twice and nothing is lost.
                    $held->restore($sessionId, $entries);
                    $queue->release($jobId);
                    Logger::logEvent('worker_answer_deferred', 'Engine taken again; messages put back', [
                        'job_id' => $jobId, 'session_id' => $sessionId, 'rows' => $rowIds,
                    ], 'warn', 'pipeline-worker');
                    $log('warn', 'engine busy again — batch put back', ['job_id' => $jobId]);
                    // Stay with the job rather than exiting: it is released, so an attached browser wins it if one is
                    // watching, but with no browser this process is the only thing that will ever answer it.
                    if ($once || microtime(true) >= $busyUntil) {
                        break;
                    }
                    usleep($busyRetryUs);
                    continue;
                }

                $log('info', "answered {$sessionId} without a client", ['rows' => $rowIds, 'chars' => mb_strlen($batchText)]);
            }
        } else {
            Logger::logEvent('worker_unknown_job', 'Unknown job type', [
                'job_id' => $jobId, 'type' => $type,
            ], 'error', 'pipeline-worker');
            $log('error', 'unknown job type', ['job_id' => $jobId, 'type' => $type]);
        }

        $queue->complete($jobId);
        $ran++;
    } catch (App\Services\ModelBusyException $e) {
        // The engine is held. If the holder is gone, that is not "busy" — free it and come straight back, rather than
        // waiting a couple of seconds to be told the same thing by a lock nobody owns.
        $reclaimed = ModelLock::reclaimDeadOwner();
        // The engine is held by somebody else — another conversation's turn, or the app's own work. Nothing failed and
        // nothing was consumed, so the job goes back untouched with no attempt counted (ruling R4: only a definite
        // outcome releases work). This process stays with it while its patience lasts, because a released job has
        // nobody guaranteed to pick it up.
        $queue->release($jobId);
        Logger::logEvent('job_deferred_engine_busy', 'Job released: the engine is held by someone else', [
            'job_id' => $jobId,
            'session_id' => $sessionId,
            'type' => $type,
            'ms' => (int)round((microtime(true) - $started) * 1000),
        ], 'info', 'pipeline-worker');
        $log('info', 'engine busy — job handed back', ['job_id' => $jobId, 'type' => $type]);
        if ($once || microtime(true) >= $busyUntil) {
            break;
        }
        usleep($reclaimed ? 200_000 : $busyRetryUs);
        continue;
    } catch (\Throwable $e) {
        // If the engine is not answering, this failure belongs to the outage and not to the work (R11): the job goes back
        // with no attempt counted, and the row stays owed — which is the line that must never blur, "still owed" is not
        // "someone gave up". With the engine up, a failure is the work's own and counts.
        if (!$engineAnswers(true)) {
            $queue->release($jobId);
            Logger::logEvent('job_deferred_engine_down', 'Job released: the engine is not answering', [
                'job_id' => $jobId,
                'session_id' => $sessionId,
                'type' => $type,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ], 'warn', 'pipeline-worker');
            $log('warn', 'engine down — job handed back', ['job_id' => $jobId, 'type' => $type]);
            if ($once || microtime(true) >= $busyUntil) {
                break;
            }
            usleep($busyRetryUs);
            continue;
        }

        $queue->fail($jobId, $e->getMessage());
        $log('error', 'job failed: ' . $e->getMessage(), ['job_id' => $jobId, 'type' => $type, 'ms' => (int)round((microtime(true) - $started) * 1000)]);
    } finally {
        if ($lockToken !== null) {
            ModelLock::clearStatus($lockToken);
            ModelLock::release($lockToken);
        }
        Logger::clearSessionId();
    }

    if ($once) {
        break;
    }
}

$counts = $queue->counts();
$log('info', 'worker exiting', ['jobs_run' => $ran, 'pending' => $counts['pending'], 'claimed' => $counts['claimed']]);
exit(0);
