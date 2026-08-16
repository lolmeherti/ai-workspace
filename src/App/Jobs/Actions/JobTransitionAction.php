<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Enums\JobState;
use App\Enums\JobHistoryReason;
use App\Jobs\JobRepository;
use App\Jobs\CvRepository;
use App\Jobs\JobStateMachine;

class JobTransitionAction extends BaseAction
{
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

        try {
            $target = JobState::from($_POST['to'] ?? '');
        } catch (\ValueError) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid target state.'], 400);
            return;
        }

        $reason = null;
        if ($target === JobState::HISTORY) {
            try {
                $reason = JobHistoryReason::from($_POST['history_reason'] ?? '');
            } catch (\ValueError) {
                $this->jsonResponse(['status' => 'error', 'message' => 'A valid history reason is required.'], 400);
                return;
            }
        }

        $at = null;
        if ($target === JobState::APPLIED) {
            $at = $this->normalizeDateTime($_POST['applied_at'] ?? '');
            $cvUuid = trim($_POST['applied_cv_uuid'] ?? '');
            if ($at === null || $cvUuid === '') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Applying requires a valid date/time and CV.'], 400);
                return;
            }
            $cv = (new CvRepository($this->db))->getByUuid($cvUuid);
            if ($cv === null) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Selected CV not found.'], 404);
                return;
            }
            $job['applied_cv_uuid'] = $cvUuid;
            $job['applied_cv_markdown'] = $cv['extracted_markdown'];
        }

        try {
            $updated = (new JobStateMachine())->transition($job, $target, $reason, $at);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
            return;
        }

        $repo->updateState($uuid, $updated);
        $this->jsonResponse(['status' => 'success', 'job' => $repo->decodeRow($repo->getByUuid($uuid))]);
    }

    private function normalizeDateTime(string $value): ?string
    {
        $timestamp = strtotime(trim($value));
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
