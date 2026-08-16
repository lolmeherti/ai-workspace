<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRunService;

class JobRunStatusAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $this->jsonResponse(['status' => 'success', 'run' => (new JobRunService($this->db))->getActiveRun()]);
    }
}
