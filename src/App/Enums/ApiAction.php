<?php

namespace App\Enums;

enum ApiAction: string
{
    case SHOW_IN_EXPLORER = 'show_in_explorer';
    case GET_FILE_CONTENT = 'get_file_content';
    case GET_CACHE = 'get_cache';
    case SYNC_LMSTUDIO_LIMIT = 'sync_lmstudio_limit';
}