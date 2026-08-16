<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\RegistryRepository;

class RegistryDeleteAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuid = $_POST['uuid'] ?? '';
        if ($uuid === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing uuid.'], 400);
            return;
        }

        $repo = new RegistryRepository($this->db);
        if ($repo->getByUuid($uuid) === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Registry entry not found.'], 404);
            return;
        }

        $repo->delete($uuid);
        $this->jsonResponse(['status' => 'success']);
    }
}
