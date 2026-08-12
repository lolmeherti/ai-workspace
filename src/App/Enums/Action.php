<?php

namespace App\Enums;

enum Action: string
{
    case CONDENSE = 'condense';
    case SAVE_SETTINGS = 'save_settings';
    case SWITCH_MODEL = 'switch_model';
    case MANUAL_CONSOLIDATE = 'manual_consolidate';
    case ADD_MEMORY = 'add_memory';
    case DELETE_MEMORY = 'delete_memory';
    case DELETE_MULTIPLE_MEMORIES = 'delete_multiple_memories';
    case DELETE_MULTIPLE_SESSIONS = 'delete_multiple_sessions';
    case UPDATE_MEMORY = 'update_memory';
    case CLEAR_ALL = 'clear_all';
    case DELETE_FILES = 'delete_files';
    case ADD_EMAIL_ACCOUNT = 'add_email_account';
    case DELETE_EMAIL_ACCOUNT = 'delete_email_account';
    case SEND_REPLY = 'send_reply';
    case TOOL_APPROVE = 'tool_approve';
}
