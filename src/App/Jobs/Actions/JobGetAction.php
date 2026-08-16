<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRepository;

class JobGetAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuid = $_GET['uuid'] ?? '';
        $repo = new JobRepository($this->db);
        $job = $uuid !== '' ? $repo->getByUuid($uuid) : null;

        if ($job === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Job not found.'], 404);
            return;
        }

        $this->jsonResponse(['status' => 'success', 'job' => $repo->decodeRow($job)]);
    }
}
