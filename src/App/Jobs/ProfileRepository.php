<?php

namespace App\Jobs;

class ProfileRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function get(): ?array
    {
        $rows = $this->db->query('SELECT * FROM job_profile WHERE id = 1');
        return $rows[0] ?? null;
    }

    public function save(array $profile): void
    {
        $data = [
            'locations' => $this->encodeList($profile['locations'] ?? null),
            'work_modes' => $this->encodeList($profile['work_modes'] ?? null),
            'employment_types' => $this->encodeList($profile['employment_types'] ?? null),
            'salary_min' => $profile['salary_min'] ?? null,
            'salary_currency' => $profile['salary_currency'] ?? null,
            'free_text' => $profile['free_text'] ?? null,
        ];

        if ($this->get() === null) {
            $this->db->insert('job_profile', ['id' => 1] + $data);
        } else {
            $this->db->update('job_profile', $data, ['id' => 1]);
        }
    }

    public static function isComplete(?array $profile): bool
    {
        if ($profile === null) {
            return false;
        }
        $locations = self::decodeList($profile['locations'] ?? null);
        $workModes = self::decodeList($profile['work_modes'] ?? null);
        return !empty($locations) && !empty($workModes);
    }

    private static function encodeList(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value);
    }

    private static function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}
