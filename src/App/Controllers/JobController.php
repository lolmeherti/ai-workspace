<?php

namespace App\Controllers;

use App\Enums\ApiAction;
use App\Jobs\Actions\CvUploadAction;
use App\Jobs\Actions\CvListAction;
use App\Jobs\Actions\CvExtractAction;
use App\Jobs\Actions\CvDeleteAction;
use App\Jobs\Actions\CvSetActiveAction;
use App\Jobs\Actions\ProfileGetAction;
use App\Jobs\Actions\ProfileSaveAction;
use App\Jobs\Actions\RegistryListAction;
use App\Jobs\Actions\RegistryAddAction;
use App\Jobs\Actions\RegistryUpdateAction;
use App\Jobs\Actions\RegistryDeleteAction;
use App\Jobs\Actions\JobListAction;
use App\Jobs\Actions\JobGetAction;
use App\Jobs\Actions\JobTransitionAction;
use App\Jobs\Actions\JobRestoreAction;
use App\Jobs\Actions\JobBatchAction;
use App\Jobs\Actions\JobEditAction;
use App\Jobs\Actions\BlockDomainAction;
use App\Jobs\Actions\BlockCompanyAction;
use App\Jobs\Actions\BlockListAction;
use App\Jobs\Actions\JobRunAction;
use App\Jobs\Actions\JobCancelAction;
use App\Jobs\Actions\JobRunStatusAction;
use App\Jobs\Actions\JobRunLogsAction;
use App\Jobs\Actions\PruneJobsAction;

class JobController extends BaseController
{
    public function __construct(private $db, private $agentManager)
    {
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $apiAction = ApiAction::tryFrom($_GET['api_action'] ?? '');

        if ($method === 'POST') {
            $this->handlePost($apiAction);
            return;
        }

        $this->handleGet($apiAction);
    }

    private function handlePost(?ApiAction $apiAction): void
    {
        match ($apiAction) {
            ApiAction::UPLOAD_CV => (new CvUploadAction($this->db))->execute(),
            ApiAction::EXTRACT_CV => (new CvExtractAction($this->db, $this->agentManager))->execute(),
            ApiAction::DELETE_CV => (new CvDeleteAction($this->db))->execute(),
            ApiAction::SET_ACTIVE_CV => (new CvSetActiveAction($this->db))->execute(),
            ApiAction::SAVE_PROFILE => (new ProfileSaveAction($this->db))->execute(),
            ApiAction::ADD_REGISTRY => (new RegistryAddAction($this->db))->execute(),
            ApiAction::UPDATE_REGISTRY => (new RegistryUpdateAction($this->db))->execute(),
            ApiAction::DELETE_REGISTRY => (new RegistryDeleteAction($this->db))->execute(),
            ApiAction::TRANSITION_JOB => (new JobTransitionAction($this->db))->execute(),
            ApiAction::RESTORE_JOB => (new JobRestoreAction($this->db))->execute(),
            ApiAction::BATCH_ACTION => (new JobBatchAction($this->db))->execute(),
            ApiAction::EDIT_JOB => (new JobEditAction($this->db))->execute(),
            ApiAction::BLOCK_DOMAIN => (new BlockDomainAction($this->db))->execute(),
            ApiAction::BLOCK_COMPANY => (new BlockCompanyAction($this->db))->execute(),
            ApiAction::RUN_JOB_SEARCH => (new JobRunAction($this->db, $this->agentManager))->execute(),
            ApiAction::CANCEL_JOB_SEARCH => (new JobCancelAction($this->db))->execute(),
            ApiAction::PRUNE_JOBS => (new PruneJobsAction($this->db))->execute(),
            default => $this->jsonResponse(['status' => 'error', 'message' => 'Invalid job action.'], 400),
        };
    }

    private function handleGet(?ApiAction $apiAction): void
    {
        match ($apiAction) {
            ApiAction::LIST_CVS => (new CvListAction($this->db))->execute(),
            ApiAction::GET_PROFILE => (new ProfileGetAction($this->db))->execute(),
            ApiAction::LIST_REGISTRY => (new RegistryListAction($this->db))->execute(),
            ApiAction::LIST_JOBS => (new JobListAction($this->db))->execute(),
            ApiAction::GET_JOB => (new JobGetAction($this->db))->execute(),
            ApiAction::GET_BLOCKS => (new BlockListAction($this->db))->execute(),
            ApiAction::GET_RUN_STATUS => (new JobRunStatusAction($this->db))->execute(),
            ApiAction::LIST_RUN_LOGS => (new JobRunLogsAction($this->db))->execute(),
            default => $this->jsonResponse(['status' => 'error', 'message' => 'Invalid job action.'], 400),
        };
    }
}
