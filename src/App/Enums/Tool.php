<?php

namespace App\Enums;

enum Tool: string
{
    case SEARCH_FILES = 'search_files';
    case CREATE_TODOIST_TASK = 'create_todoist_task';
    case GET_TODOIST_TASKS = 'get_todoist_tasks';
    case DELETE_TODOIST_TASK = 'delete_todoist_task';
    case UPDATE_TODOIST_TASK = 'update_todoist_task';
}