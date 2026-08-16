<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\BlockRepository;
use App\Jobs\JobMatcher;
use App\Jobs\JobRepository;

class BlockCompanyAction extends BaseAction
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

        $company = trim((string) ($job['company'] ?? ''));
        if ($company === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Job has no company.'], 400);
            return;
        }

        (new BlockRepository($this->db))->add('company', $company);

        $toDelete = [];
        foreach ($repo->getAllByState('unread') as $unread) {
            if (JobMatcher::isCompanyBlocked([$company], $unread['company'])) {
                $toDelete[] = $unread['uuid'];
            }
        }

        $this->jsonResponse([
            'status' => 'success',
            'company' => $company,
            'deleted' => $repo->deleteMany($toDelete),
            'counts' => $repo->countsByState(),
        ]);
    }
}
