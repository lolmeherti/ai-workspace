<?php

namespace App\Enums;

enum JobState: string
{
    case UNREAD = 'unread';
    case INTERESTED = 'interested';
    case APPLIED = 'applied';
    case INTERVIEW = 'interview';
    case OFFER = 'offer';
    case HISTORY = 'history';
}
