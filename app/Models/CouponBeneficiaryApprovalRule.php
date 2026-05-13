<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponBeneficiaryApprovalRule extends Model
{
    protected $fillable = [
        'min_beneficiaries',
        'max_beneficiaries',
        'required_approvals',
    ];
}
