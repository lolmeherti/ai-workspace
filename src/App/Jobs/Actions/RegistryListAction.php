<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\RegistryRepository;

class RegistryListAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $repo = new RegistryRepository($this->db);
        $this->jsonResponse(['status' => 'success', 'entries' => $repo->listAll()]);
    }
}
