<?php

namespace App\Enums;

enum Tool: string
{
    case SEARCH_WEB = 'search_web';
    case SEARCH_FILES = 'search_files';
    case SEARCH_LOCAL = 'search_local';
    case SEARCH_CALENDAR = 'search_calendar';
    case CREATE_TODOIST_TASK = 'create_todoist_task';
    case GET_TODOIST_TASKS = 'get_todoist_tasks';
    case DELETE_TODOIST_TASK = 'delete_todoist_task';
    case UPDATE_TODOIST_TASK = 'update_todoist_task';
    case GET_EMAIL_BRIEFING = 'get_email_briefing';
    case SEARCH_MEMORIES = 'search_memories';
}
