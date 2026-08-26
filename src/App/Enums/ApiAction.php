<?php

namespace App\Enums;

enum ApiAction: string
{
    case SHOW_IN_EXPLORER = 'show_in_explorer';
    case GET_FILE_CONTENT = 'get_file_content';
    case SYNC_LMSTUDIO_LIMIT = 'sync_lmstudio_limit';
    case GET_SWITCH_STATUS = 'get_switch_status';
    case SEARCH_FILES = 'search_files';

    case OPEN_DRAFT = 'open_draft';
    case UPDATE_DRAFT = 'update_draft';
    case SAVE_DRAFT = 'save_draft';
    case DISCARD_DRAFT = 'discard_draft';
    case DELETE_DRAFT_BLOCKS = 'delete_draft_blocks';

    case SYNC_FILES = 'sync_files';
    case UPLOAD_FILE = 'upload_file';
    case CREATE_TODOIST_TASK = 'create_todoist_task';

    case DELETE_TODOIST_TASK = 'delete_todoist_task';

    case GET_EMAILS = 'get_emails';
    case GET_EMAIL_BODY = 'get_email_body';

    case UPLOAD_CV = 'upload_cv';
    case LIST_CVS = 'list_cvs';
    case EXTRACT_CV = 'extract_cv';
    case DELETE_CV = 'delete_cv';
    case SET_ACTIVE_CV = 'set_active_cv';
    case GET_PROFILE = 'get_profile';
    case SAVE_PROFILE = 'save_profile';
    case LIST_REGISTRY = 'list_registry';
    case ADD_REGISTRY = 'add_registry';
    case UPDATE_REGISTRY = 'update_registry';
    case DELETE_REGISTRY = 'delete_registry';

    case LIST_JOBS = 'list_jobs';
    case GET_JOB = 'get_job';
    case TRANSITION_JOB = 'transition_job';
    case RESTORE_JOB = 'restore_job';
    case BATCH_ACTION = 'batch_action';
    case EDIT_JOB = 'edit_job';
    case BLOCK_DOMAIN = 'block_domain';
    case BLOCK_COMPANY = 'block_company';
    case GET_BLOCKS = 'get_blocks';

    case RUN_JOB_SEARCH = 'run_job_search';
    case CANCEL_JOB_SEARCH = 'cancel_job_search';
    case GET_RUN_STATUS = 'get_run_status';
    case LIST_RUN_LOGS = 'list_run_logs';
    case PRUNE_JOBS = 'prune_jobs';
}