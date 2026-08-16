<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\BlockRepository;

class BlockListAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $this->jsonResponse(['status' => 'success', 'blocks' => (new BlockRepository($this->db))->listActive()]);
    }
}
