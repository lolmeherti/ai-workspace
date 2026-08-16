<?php

namespace App\Enums;

enum JobHistoryReason: string
{
    case NOT_INTERESTED = 'not_interested';
    case REJECTED_BY_COMPANY = 'rejected_by_company';
    case OFFER_REJECTED = 'offer_rejected';
    case OFFER_ACCEPTED = 'offer_accepted';
}
