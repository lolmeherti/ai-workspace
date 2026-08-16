<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\CvRepository;

class CvListAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $repo = new CvRepository($this->db);
        $this->jsonResponse(['status' => 'success', 'cvs' => $repo->listSelectable()]);
    }
}
