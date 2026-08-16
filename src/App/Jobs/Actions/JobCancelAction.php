<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRunService;

class JobCancelAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $runUuid = trim($_POST['run_uuid'] ?? '');
        $service = new JobRunService($this->db);

        if ($runUuid === '') {
            $active = $service->getActiveRun();
            $runUuid = $active['uuid'] ?? '';
        }
        if ($runUuid !== '') {
            $service->requestCancel($runUuid);
        }

        $this->jsonResponse(['status' => 'success']);
    }
}
