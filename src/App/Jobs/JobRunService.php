<?php

namespace App\Jobs;

use App\Cache;
use App\Logger;

class JobRunService
{
    private JobRunRepository $runs;

    private $emit = null;

    public function __construct(private $db)
    {
        $this->runs = new JobRunRepository($db);
    }

    public function setEmitter(callable $emit): void
    {
        $this->emit = $emit;
    }

    public function isLocked(): bool
    {
        return $this->runs->getActiveRun() !== null;
    }

    public function getActiveRun(): ?array
    {
        return $this->runs->getActiveRun();
    }

    public function start(string $cvUuid, ?array $profileSnapshot): string
    {
        $this->runs->clearLogs();
        return $this->runs->create($cvUuid, $profileSnapshot);
    }

    public function requestCancel(string $runUuid): void
    {
        try {
            Cache::set(self::cancelKey($runUuid), '1', 3600);
        } catch (\Throwable $e) {
            Logger::logEvent('job_cancel_failed', 'Could not set cancel flag', [
                'run_uuid' => $runUuid,
                'error' => $e->getMessage(),
            ], 'warn', 'JobRunService');
        }
    }

    public function isCancelled(string $runUuid): bool
    {
        try {
            return Cache::get(self::cancelKey($runUuid)) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public function clearCancel(string $runUuid): void
    {
        try {
            Cache::delete(self::cancelKey($runUuid));
        } catch (\Throwable) {
        }
    }

    public function complete(string $runUuid, array $summary): void
    {
        $this->runs->complete($runUuid, $summary);
    }

    public function markCancelled(string $runUuid): void
    {
        $this->runs->cancel($runUuid);
    }

    public function log(string $runUuid, string $level, string $message, ?array $context = null): void
    {
        $this->runs->log($runUuid, $level, $message, $context);
        if ($this->emit !== null) {
            ($this->emit)('run_log', [
                'run_uuid' => $runUuid,
                'level' => $level,
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function listLogs(string $runUuid): array
    {
        return $this->runs->listLogs($runUuid);
    }

    private static function cancelKey(string $runUuid): string
    {
        return 'job_run_cancel:' . $runUuid;
    }
}
