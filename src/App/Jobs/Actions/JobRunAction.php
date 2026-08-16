<?php

namespace App\Jobs\Actions;

use App\Actions\BaseAction;
use App\Logger;
use App\Jobs\CvRepository;
use App\Jobs\JobOrchestrator;
use App\Jobs\JobRunService;
use App\Jobs\ProfileRepository;

class JobRunAction extends BaseAction
{
    public function __construct(private $db, private $agentManager)
    {
    }

    public function execute(): void
    {
        $cvUuid = trim($_POST['cv_uuid'] ?? '');
        $service = new JobRunService($this->db);

        $cv = $cvUuid !== '' ? (new CvRepository($this->db))->getByUuid($cvUuid) : null;
        if ($cv === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Select a CV first.'], 400);
            return;
        }
        if (trim((string) ($cv['extracted_markdown'] ?? '')) === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'The selected CV has no extracted details. Run Extract Details first.'], 400);
            return;
        }
        $profile = (new ProfileRepository($this->db))->get();
        if (!ProfileRepository::isComplete($profile)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'The job profile is incomplete (needs at least one location and one work mode).'], 400);
            return;
        }
        if ($service->isLocked()) {
            $this->jsonResponse(['status' => 'error', 'message' => 'A job search is already running.'], 409);
            return;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        echo str_repeat(' ', 4096) . "\n\n";
        @flush();

        $emit = function (string $event, array $data): void {
            echo "data: " . json_encode(['event' => $event, 'data' => $data]) . "\n\n";
            @ob_flush();
            @flush();
        };

        $service->setEmitter($emit);

        $runUuid = $service->start($cvUuid, $profile);
        $emit('run_start', ['run_uuid' => $runUuid]);

        try {
            $orchestrator = new JobOrchestrator($this->db, $this->agentManager, $service);
            $summary = $orchestrator->run($runUuid, $cv['extracted_markdown'], $this->profileArray($profile), $emit);
            $emit('run_complete', ['summary' => $summary]);
        } catch (\Throwable $e) {
            $service->markCancelled($runUuid);
            Logger::logEvent('job_run_error', 'Job run failed: ' . $e->getMessage(), ['run_uuid' => $runUuid], 'error', 'JobRunAction');
            $emit('error', ['message' => $e->getMessage()]);
        }

        $emit('done', []);
    }

    private function profileArray(array $profile): array
    {
        foreach (['locations', 'work_modes', 'employment_types'] as $field) {
            if (isset($profile[$field]) && is_string($profile[$field])) {
                $decoded = json_decode($profile[$field], true);
                $profile[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($profile['id'], $profile['updated_at']);
        return $profile;
    }
}
