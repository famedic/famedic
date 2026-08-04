<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case LabResults = 'lab_results';
    case CouponCreation = 'coupon_creation';
    case CouponAuthorizationApproval = 'coupon_authorization_approval';
}
