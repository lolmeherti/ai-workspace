<?php

namespace App\Enums;

enum ApiAction: string
{
    case SHOW_IN_EXPLORER = 'show_in_explorer';
    case GET_FILE_CONTENT = 'get_file_content';
    case GET_CACHE = 'get_cache';
    case SYNC_LMSTUDIO_LIMIT = 'sync_lmstudio_limit';
    case SEARCH_FILES = 'search_files';
    
    case OPEN_DRAFT = 'open_draft';
    case UPDATE_DRAFT = 'update_draft';
    case SAVE_DRAFT = 'save_draft';
    case DISCARD_DRAFT = 'discard_draft';
    case DELETE_DRAFT_BLOCKS = 'delete_draft_blocks';

    case DELETE_TODOIST_TASK = 'delete_todoist_task';

    case GET_EMAILS = 'get_emails';
    case GET_EMAIL_BODY = 'get_email_body';
}