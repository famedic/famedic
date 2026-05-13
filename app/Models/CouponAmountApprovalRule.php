<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponAmountApprovalRule extends Model
{
    protected $fillable = [
        'min_amount_cents',
        'max_amount_cents',
        'required_approvals',
    ];
}

