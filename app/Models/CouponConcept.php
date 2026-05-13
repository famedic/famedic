<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponConcept extends Model
{
    protected $fillable = [
        'title',
        'description',
    ];

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'coupon_concept_id');
    }
}
