<?php

namespace App\Enums;

enum Tool: string
{
    case SEARCH_WEB = 'search_web';
    case SEARCH_FILES = 'search_files';
    case SEARCH_LOCAL = 'search_local';
    case SEARCH_CALENDAR = 'search_calendar';
    case CREATE_CALENDAR_TASK = 'create_calendar_task';
    case GET_CALENDAR_TASKS = 'get_calendar_tasks';
    case DELETE_CALENDAR_TASK = 'delete_calendar_task';
    case UPDATE_CALENDAR_TASK = 'update_calendar_task';
    case GET_EMAIL_BRIEFING = 'get_email_briefing';
    case SEARCH_MEMORIES = 'search_memories';
    case SEARCH_SESSION_EVIDENCE = 'search_session_evidence';
}
