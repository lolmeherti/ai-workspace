<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Jobs\JobRepository;

class JobEditAction extends BaseAction
{
    private const SCALAR_FIELDS = [
        'title', 'company', 'description', 'location', 'city', 'country',
        'employment_type', 'salary', 'applicant_count', 'source_domain', 'url',
        'ai_selection_comment', 'offer_compensation', 'offer_notes',
    ];

    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuid = $_POST['uuid'] ?? '';
        $repo = new JobRepository($this->db);
        $job = $uuid !== '' ? $repo->getByUuid($uuid) : null;

        if ($job === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Job not found.'], 404);
            return;
        }

        $data = [];
        foreach (self::SCALAR_FIELDS as $field) {
            if (array_key_exists($field, $_POST)) {
                $data[$field] = trim((string) $_POST[$field]);
            }
        }

        if (array_key_exists('work_mode', $_POST)) {
            $data['work_mode'] = $this->normalizeWorkMode($_POST['work_mode']);
        }
        if (array_key_exists('posted_at', $_POST)) {
            $normalized = $this->normalizeDateTime($_POST['posted_at']);
            if ($normalized === null) {
                $this->jsonResponse(['status' => 'error', 'message' => 'posted_at must be a valid date/time.'], 400);
                return;
            }
            $data['posted_at'] = $normalized;
        }
        if (array_key_exists('offer_deadline', $_POST)) {
            $data['offer_deadline'] = $this->normalizeDateTime($_POST['offer_deadline']);
        }
        if (array_key_exists('interview_timestamps', $_POST)) {
            $data['interview_timestamps'] = $this->parseTimestampList($_POST['interview_timestamps']);
        }

        if (empty($data)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No editable fields provided.'], 400);
            return;
        }

        $repo->update($uuid, $data);
        $this->jsonResponse(['status' => 'success', 'job' => $repo->decodeRow($repo->getByUuid($uuid))]);
    }

    private function normalizeWorkMode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return in_array($value, ['remote', 'hybrid', 'on_site'], true) ? $value : null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime(trim($value));
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function parseTimestampList(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
