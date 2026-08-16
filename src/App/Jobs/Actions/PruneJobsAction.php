<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRepository;
use App\Jobs\JobRunRepository;

class PruneJobsAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $jobRepo = new JobRepository($this->db);
        $runRepo = new JobRunRepository($this->db);

        $jobsDeleted = $jobRepo->deleteAll();

        $runsDeleted = (int)($this->db->query('SELECT COUNT(*) AS c FROM job_runs')[0]['c'] ?? 0);
        $logsDeleted = (int)($this->db->query('SELECT COUNT(*) AS c FROM job_run_logs')[0]['c'] ?? 0);
        $runRepo->clearAll();

        $this->jsonResponse([
            'status' => 'success',
            'jobs_deleted' => $jobsDeleted,
            'runs_deleted' => $runsDeleted,
            'logs_deleted' => $logsDeleted,
        ]);
    }
}
