<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\CvRepository;

class CvDeleteAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuid = $_POST['cv_uuid'] ?? '';
        if ($uuid === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing cv_uuid.'], 400);
            return;
        }

        $repo = new CvRepository($this->db);
        if ($repo->getByUuid($uuid) === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'CV not found.'], 404);
            return;
        }

        $repo->softDelete($uuid);
        $this->jsonResponse(['status' => 'success']);
    }
}
