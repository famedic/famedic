<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        $cents = fake()->numberBetween(10000, 50000);

        return [
            'code' => null,
            'amount_cents' => $cents,
            'remaining_cents' => $cents,
            'type' => CouponType::Balance,
            'is_active' => true,
        ];
    }
}
