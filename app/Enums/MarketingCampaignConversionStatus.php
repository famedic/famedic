<?php

namespace App\Enums;

enum MarketingCampaignConversionStatus: string
{
    case Created = 'created';
    case AlreadyRecorded = 'already_recorded';
    case NotAttributed = 'not_attributed';
    case InvalidAttribution = 'invalid_attribution';
    case Failed = 'failed';
}
