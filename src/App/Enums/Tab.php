<?php

namespace App\Enums;

enum Tab: string
{
    case CHATS = 'chats';
    case MEMORIES = 'memories';
    case QUERIES = 'queries';
    case EMAILS = 'emails';
}