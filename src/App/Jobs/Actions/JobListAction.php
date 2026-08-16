<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRepository;

class JobListAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $repo = new JobRepository($this->db);
        $state = $_GET['state'] ?? '';

        if ($state === '') {
            $this->jsonResponse(['status' => 'success', 'counts' => $repo->countsByState()]);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 10)));

        $jobs = array_map(fn($job) => $repo->decodeRow($job), $repo->listByState($state, $page, $perPage));

        $this->jsonResponse([
            'status' => 'success',
            'counts' => $repo->countsByState(),
            'state' => $state,
            'page' => $page,
            'total' => $repo->countByState($state),
            'jobs' => $jobs,
        ]);
    }
}
