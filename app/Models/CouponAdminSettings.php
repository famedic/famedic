<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponAdminSettings extends Model
{
    protected $table = 'coupon_admin_settings';

    protected $fillable = [
        'base_amount_cents',
        'max_assignment_amount_cents',
        'max_assignments_per_day',
        'authorization_email',
        'require_authorization',
        'amount_threshold_cents',
        'required_approvals_by_amount',
        'mass_campaign_threshold',
        'superadmin_bypass_approvals',
    ];

    protected function casts(): array
    {
        return [
            'require_authorization' => 'boolean',
            'superadmin_bypass_approvals' => 'boolean',
        ];
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'base_amount_cents' => 50000,
                'require_authorization' => false,
                'required_approvals_by_amount' => 0,
                'superadmin_bypass_approvals' => true,
            ]
        );
    }
}
