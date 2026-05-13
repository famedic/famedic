<?php

namespace App\Enums;

enum CouponApprovalStatus: string
{
    case PendingAuthorization = 'pending_authorization';
    case Active = 'active';
    case Rejected = 'rejected';
}
