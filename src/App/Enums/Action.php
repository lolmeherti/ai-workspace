<?php

namespace App\Enums;

enum Action: string
{
    case CONDENSE = 'condense';
    case SAVE_SETTINGS = 'save_settings';
    case MANUAL_CONSOLIDATE = 'manual_consolidate';
    case ADD_MEMORY = 'add_memory';
    case DELETE_MEMORY = 'delete_memory';
    case DELETE_MULTIPLE_MEMORIES = 'delete_multiple_memories';
    case DELETE_MULTIPLE_SESSIONS = 'delete_multiple_sessions';
    case UPDATE_MEMORY = 'update_memory';
    case DELETE_QUERY = 'delete_query';
    case CLEAR_ALL = 'clear_all';
}