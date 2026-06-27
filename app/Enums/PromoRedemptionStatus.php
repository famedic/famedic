<?php

namespace App\Enums;

enum PromoRedemptionStatus: string
{
    case Validated = 'validated';
    case Reserved = 'reserved';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Failed = 'failed';
}
