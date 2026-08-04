<?php

namespace App\Enums;

enum CouponBeneficiarySource: string
{
    case Manual = 'manual';
    case Excel = 'excel';
    case Legacy = 'legacy';
}
