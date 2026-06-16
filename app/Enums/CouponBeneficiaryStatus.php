<?php

namespace App\Enums;

enum CouponBeneficiaryStatus: string
{
    case PendingUser = 'pending_user';
    case Assigned = 'assigned';
    case Cancelled = 'cancelled';
}
