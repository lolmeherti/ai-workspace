<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Enums\JobState;
use App\Enums\JobHistoryReason;
use App\Jobs\JobRepository;
use App\Jobs\JobStateMachine;

class JobBatchAction extends BaseAction
{
    public function __construct(private $db)
    {
    }

    public function execute(): void
    {
        $uuids = $this->readUuids();
        $action = $_POST['action'] ?? '';
        $repo = new JobRepository($this->db);

        if (empty($uuids)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No jobs selected.'], 400);
            return;
        }

        if ($action === 'delete') {
            $deleted = $repo->deleteMany($uuids);
            $this->jsonResponse(['status' => 'success', 'updated' => $deleted, 'counts' => $repo->countsByState()]);
            return;
        }

        if ($action === 'restore') {
            $updated = $this->runRestore($repo, $uuids);
            $this->jsonResponse(['status' => 'success', 'updated' => $updated, 'counts' => $repo->countsByState()]);
            return;
        }

        $spec = $this->transitionSpec($action);
        if ($spec === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unknown batch action.'], 400);
            return;
        }

        $machine = new JobStateMachine();
        $updated = 0;
        foreach ($uuids as $uuid) {
            $job = $repo->getByUuid($uuid);
            if ($job === null) {
                continue;
            }
            try {
                $next = $machine->transition($job, $spec['to'], $spec['reason']);
                $repo->updateState($uuid, $next);
                $updated++;
            } catch (\InvalidArgumentException) {
                // skip jobs that are no longer in a compatible state
            }
        }

        $this->jsonResponse(['status' => 'success', 'updated' => $updated, 'counts' => $repo->countsByState()]);
    }

    private function runRestore(JobRepository $repo, array $uuids): int
    {
        $machine = new JobStateMachine();
        $updated = 0;
        foreach ($uuids as $uuid) {
            $job = $repo->getByUuid($uuid);
            if ($job === null) {
                continue;
            }
            try {
                $next = $machine->restore($job);
                $repo->updateState($uuid, $next);
                $updated++;
            } catch (\InvalidArgumentException) {
                // skip jobs not in history
            }
        }
        return $updated;
    }

    private function transitionSpec(string $action): ?array
    {
        return match ($action) {
            'interested' => ['to' => JobState::INTERESTED, 'reason' => null],
            'not_interested' => ['to' => JobState::HISTORY, 'reason' => JobHistoryReason::NOT_INTERESTED],
            'move_to_interview' => ['to' => JobState::INTERVIEW, 'reason' => null],
            'rejected_by_company' => ['to' => JobState::HISTORY, 'reason' => JobHistoryReason::REJECTED_BY_COMPANY],
            'move_to_offer' => ['to' => JobState::OFFER, 'reason' => null],
            'offer_accepted' => ['to' => JobState::HISTORY, 'reason' => JobHistoryReason::OFFER_ACCEPTED],
            'offer_rejected' => ['to' => JobState::HISTORY, 'reason' => JobHistoryReason::OFFER_REJECTED],
            default => null,
        };
    }

    private function readUuids(): array
    {
        $raw = $_POST['uuids'] ?? '';
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
