<?php

namespace App\Enums;

enum MarketingCampaignLinkPublicAvailability: string
{
    case Available = 'available';
    case NotFound = 'not_found';
    case NotStarted = 'not_started';
    case Paused = 'paused';
    case Expired = 'expired';
    case Archived = 'archived';
    case InvalidTarget = 'invalid_target';
}
