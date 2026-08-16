<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\BlockRepository;
use App\Jobs\JobMatcher;
use App\Jobs\JobRepository;

class BlockDomainAction extends BaseAction
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

        $domain = JobMatcher::normalizeDomain($job['source_domain']);
        if ($domain === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Job has no source domain.'], 400);
            return;
        }

        (new BlockRepository($this->db))->add('domain', $domain);

        $toDelete = [];
        foreach ($repo->getAllByState('unread') as $unread) {
            if (JobMatcher::isDomainBlocked([$domain], $unread['source_domain'])) {
                $toDelete[] = $unread['uuid'];
            }
        }

        $this->jsonResponse([
            'status' => 'success',
            'domain' => $domain,
            'deleted' => $repo->deleteMany($toDelete),
            'counts' => $repo->countsByState(),
        ]);
    }
}
