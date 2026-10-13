<?php

declare(strict_types=1);

namespace App\Services;

use App\Logger;

/**
 * Starts the pipeline worker, and queues the work that made it necessary.
 *
 * The rule this exists to honour: the app's own work (building a source's notes and contents index, answering messages
 * held while the engine was busy) must finish even if the browser is closed, so it cannot live inside a request. The
 * request only queues a job and launches the worker as a detached child, then forgets about it — `proc_open` children
 * were measured to survive both a completed request and a client that walked away mid-request
 * (`src/tests/live/spawn-detach-probe.php`).
 *
 * Never waits on the child: no `proc_close`, no status polling loop. If the spawn fails, the rows simply stay raw and
 * the turn-start backstop reclaims them later — a failure here is never allowed to affect an answer already delivered.
 *
 * See `.hermes/plans/evidence/2026-10-11_idle-enrichment-and-queued-followups.md`.
 */
final class PipelineWorker
{
    private const SCRIPT = '/pipeline-worker.php';
    private const STDIO_LOG = '/logs/pipeline-worker.out.log';

    /**
     * Queue the enrichment of a finished turn's fetch rows and start the worker. Returns the job id, or null when the
     * turn fetched nothing (no rows) or when queueing failed.
     *
     * The worker is started EVEN WHEN there is nothing new to enrich, because a job may already be waiting from an
     * earlier attempt: the worker hands a job back when the engine is busy, and without a nudge it would sit in the
     * queue until some later turn happened to fetch something (measured: a handed-back enrich job stayed pending after
     * the turn that caused it finished). A worker with nothing to claim exits immediately, so the cost of the nudge is
     * one process start.
     *
     * @param int[] $rowIds
     */
    public static function enqueueEnrichment(int $sessionId, array $rowIds): ?string
    {
        $rowIds = array_values(array_unique(array_filter(
            array_map('intval', $rowIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($rowIds === []) {
            self::spawn();

            return null;
        }

        try {
            $jobId = (new JobQueue())->enqueue(JobQueue::TYPE_ENRICH, ['row_ids' => $rowIds], $sessionId);
        } catch (\Throwable $e) {
            Logger::logEvent('worker_enqueue_failed', 'Could not queue the enrichment job: ' . $e->getMessage(), [
                'session_id' => $sessionId,
                'row_ids' => $rowIds,
            ], 'error', 'PipelineWorker::enqueueEnrichment');

            return null;
        }

        self::spawn();

        return $jobId;
    }

    /** Start the worker detached. Returns whether the process was launched (its outcome is not this process's business). */
    public static function spawn(): bool
    {
        $root = dirname(__DIR__, 2);
        $command = [self::phpBinary(), $root . self::SCRIPT];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $root . self::STDIO_LOG, 'a'],
            2 => ['file', $root . self::STDIO_LOG, 'a'],
        ];

        try {
            $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } catch (\Throwable $e) {
            $process = false;
        }

        if (!is_resource($process)) {
            Logger::logEvent('worker_spawn_failed', 'Could not start the pipeline worker', [
                'command' => implode(' ', $command),
            ], 'error', 'PipelineWorker::spawn');

            return false;
        }

        // Deliberately not closed and not waited on: the child has to outlive this request.
        Logger::logEvent('worker_spawned', 'Pipeline worker started', [
            'pid' => proc_get_status($process)['pid'] ?? null,
        ], 'info', 'PipelineWorker::spawn');

        return true;
    }

    /**
     * The PHP CLI, not PHP_BINARY: under Apache's mod_php PHP_BINARY points at the httpd executable, and the worker must
     * run as a plain CLI script.
     */
    private static function phpBinary(): string
    {
        $candidate = rtrim((string)PHP_BINDIR, '/') . '/php';

        return is_executable($candidate) ? $candidate : 'php';
    }
}
