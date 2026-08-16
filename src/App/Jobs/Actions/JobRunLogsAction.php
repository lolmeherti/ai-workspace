<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRunRepository;

class JobRunLogsAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $runs = new JobRunRepository($this->db);
        $run = $runs->getLatestRun();

        $this->jsonResponse([
            'status' => 'success',
            'run' => $run,
            'logs' => $run === null ? [] : $runs->listLogs($run['uuid']),
        ]);
    }
}
