<?php

namespace Database\Factories;

use App\Enums\PromoType;
use App\Models\Coupon;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory()->couponType(),
            'code' => strtoupper(Str::random(8)),
            'promo_type' => PromoType::Shared,
            'max_redemptions' => 100,
            'max_uses_per_user' => 1,
            'redemptions_count' => 0,
            'is_active' => true,
        ];
    }
}
