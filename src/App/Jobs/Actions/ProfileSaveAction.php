<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\ProfileRepository;

class ProfileSaveAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $repo = new ProfileRepository($this->db);
        $repo->save([
            'locations' => $this->decodeList($_POST['locations'] ?? null),
            'work_modes' => $this->decodeList($_POST['work_modes'] ?? null),
            'employment_types' => $this->decodeList($_POST['employment_types'] ?? null),
            'salary_min' => $this->nullable($_POST['salary_min'] ?? null),
            'salary_currency' => $this->nullable($_POST['salary_currency'] ?? null),
            'free_text' => $this->nullable($_POST['free_text'] ?? null),
        ]);

        $profile = $repo->get();
        $this->jsonResponse([
            'status' => 'success',
            'profile' => $profile,
            'complete' => ProfileRepository::isComplete($profile),
        ]);
    }

    private function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private function nullable(mixed $value): mixed
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
