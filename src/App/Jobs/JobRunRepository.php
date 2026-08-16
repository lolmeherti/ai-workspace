<?php

namespace App\Jobs;

class JobRunRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create(string $selectedCvUuid, ?array $profileSnapshot): string
    {
        $uuid = Uuid::v4();
        $this->db->insert('job_runs', [
            'uuid' => $uuid,
            'selected_cv_uuid' => $selectedCvUuid,
            'started_at' => date('Y-m-d H:i:s'),
            'profile_snapshot' => $profileSnapshot === null ? null : json_encode($profileSnapshot),
            'status' => 'running',
        ]);
        return $uuid;
    }

    public function getByUuid(string $uuid): ?array
    {
        $rows = $this->db->query('SELECT * FROM job_runs WHERE uuid = :uuid', [':uuid' => $uuid]);
        return $rows[0] ?? null;
    }

    public function getActiveRun(): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM job_runs WHERE status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        return $rows[0] ?? null;
    }

    public function getLatestRun(): ?array
    {
        $rows = $this->db->query('SELECT * FROM job_runs ORDER BY started_at DESC LIMIT 1');
        return $rows[0] ?? null;
    }

    public function cancelOrphanedRuns(): int
    {
        $stmt = $this->db->executeStatement(
            "UPDATE job_runs SET status = 'cancelled', completed_at = NOW() WHERE status = 'running'"
        );
        return $stmt->rowCount();
    }

    public function complete(string $uuid, array $summary): void
    {
        $data = array_intersect_key($summary, array_flip([
            'jobs_scraped',
            'jobs_selected',
            'sources_attempted',
            'sources_failed',
            'generated_queries',
        ]));
        $data['status'] = 'completed';
        $data['completed_at'] = date('Y-m-d H:i:s');

        if (isset($data['generated_queries']) && is_array($data['generated_queries'])) {
            $data['generated_queries'] = json_encode($data['generated_queries']);
        }

        $this->db->update('job_runs', $data, ['uuid' => $uuid]);
    }

    public function cancel(string $uuid): void
    {
        $this->db->update(
            'job_runs',
            ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')],
            ['uuid' => $uuid]
        );
    }

    public function clearAll(): void
    {
        $this->db->query('DELETE FROM job_runs');
        $this->db->query('DELETE FROM job_run_logs');
    }

    public function clearLogs(): void
    {
        $this->db->query('DELETE FROM job_run_logs');
    }

    public function log(string $runUuid, string $level, string $message, ?array $context = null): void
    {
        $this->db->insert('job_run_logs', [
            'run_uuid' => $runUuid,
            'level' => $level,
            'message' => $message,
            'context' => $context === null ? null : json_encode($context),
        ]);
    }

    public function listLogs(string $runUuid): array
    {
        return $this->db->query(
            'SELECT * FROM job_run_logs WHERE run_uuid = :run_uuid ORDER BY id ASC',
            [':run_uuid' => $runUuid]
        );
    }
}
