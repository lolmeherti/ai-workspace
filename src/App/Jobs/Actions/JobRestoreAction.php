<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRepository;
use App\Jobs\JobStateMachine;

class JobRestoreAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuid = $_POST['uuid'] ?? '';
        $repo = new JobRepository($this->db);
        $job = $uuid !== '' ? $repo->getByUuid($uuid) : null;

        if ($job === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Job not found.'], 404);
            return;
        }

        try {
            $updated = (new JobStateMachine())->restore($job);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
            return;
        }

        $repo->updateState($uuid, $updated);
        $this->jsonResponse(['status' => 'success', 'job' => $repo->decodeRow($repo->getByUuid($uuid))]);
    }
}
