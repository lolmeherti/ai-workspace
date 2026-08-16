<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\RegistryRepository;

class RegistryUpdateAction extends BaseAction
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
        $entry = $repo->getByUuid($uuid);
        if ($entry === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Registry entry not found.'], 404);
            return;
        }

        $url = trim((string) ($_POST['url'] ?? $entry['url']));

        $placeholders = $entry['placeholders'] ?? [];
        if (array_key_exists('job_title', $_POST) || array_key_exists('location', $_POST)) {
            $placeholders = $this->buildPlaceholders(
                $_POST['job_title'] ?? $this->joinValues($placeholders['job_title'] ?? []),
                $_POST['location'] ?? $this->joinValues($placeholders['location'] ?? [])
            );
        }

        $result = $repo->updateTemplate($uuid, $url, $placeholders);

        if (!$result['updated']) {
            $this->jsonResponse(['status' => 'error', 'message' => 'A source with this template and values already exists.'], 409);
            return;
        }

        $this->jsonResponse(['status' => 'success', 'entry' => $repo->getByUuid($uuid)]);
    }

    private function buildPlaceholders(string $jobTitle, string $location): array
    {
        $placeholders = [];
        $jobTitleValues = self::parseValues($jobTitle);
        $locationValues = self::parseValues($location);
        if ($jobTitleValues !== []) {
            $placeholders['job_title'] = $jobTitleValues;
        }
        if ($locationValues !== []) {
            $placeholders['location'] = $locationValues;
        }
        return $placeholders;
    }

    private function joinValues(array $values): string
    {
        return implode(', ', $values);
    }

    private static function parseValues(string $raw): array
    {
        $values = array_map('trim', explode(',', $raw));
        return array_values(array_filter($values, fn($v) => $v !== ''));
    }
}
