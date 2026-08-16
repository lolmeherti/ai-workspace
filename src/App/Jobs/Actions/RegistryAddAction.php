<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\RegistryRepository;

class RegistryAddAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $url = trim($_POST['url'] ?? '');
        if ($url === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'URL is required.'], 400);
            return;
        }

        $placeholders = $this->buildPlaceholders($_POST['job_title'] ?? '', $_POST['location'] ?? '');

        $repo = new RegistryRepository($this->db);
        $result = $repo->addTemplate($url, $placeholders);

        $this->jsonResponse([
            'status' => 'success',
            'created' => $result['created'],
            'uuid' => $result['uuid'],
            'message' => $result['created'] ? 'Source added.' : 'Source already exists.',
        ]);
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

    private static function parseValues(string $raw): array
    {
        $values = array_map('trim', explode(',', $raw));
        return array_values(array_filter($values, fn($v) => $v !== ''));
    }
}
