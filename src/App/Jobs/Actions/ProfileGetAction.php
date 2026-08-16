<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\ProfileRepository;

class ProfileGetAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $repo = new ProfileRepository($this->db);
        $profile = $repo->get();

        if ($profile !== null) {
            foreach (['locations', 'work_modes', 'employment_types'] as $field) {
                if (isset($profile[$field]) && is_string($profile[$field])) {
                    $profile[$field] = json_decode($profile[$field], true) ?: [];
                }
            }
        }

        $this->jsonResponse([
            'status' => 'success',
            'profile' => $profile,
            'complete' => ProfileRepository::isComplete($profile),
        ]);
    }
}
