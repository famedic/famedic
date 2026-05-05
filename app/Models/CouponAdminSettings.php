<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponAdminSettings extends Model
{
    protected $table = 'coupon_admin_settings';

    protected $fillable = [
        'max_assignment_amount_cents',
        'max_assignments_per_day',
        'authorization_email',
        'require_authorization',
    ];

    protected function casts(): array
    {
        return [
            'require_authorization' => 'boolean',
        ];
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'require_authorization' => false,
            ]
        );
    }
}
