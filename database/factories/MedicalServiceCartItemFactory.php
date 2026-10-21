<?php

namespace Database\Factories;

use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicalServiceCartItemFactory extends Factory
{
    protected $model = MedicalServiceCartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => MedicalServiceCart::factory(),
            'item_type' => MedicalServiceCartItem::ITEM_TYPE_MEMBERSHIP,
            'membership_plan_code' => MedicalServiceCartItem::BASIC_ANNUAL_PLAN,
            'name' => 'Plan Básico',
            'quantity' => 1,
            'unit_price_cents' => 30000,
            'subtotal_cents' => 30000,
            'requires_appointment' => false,
        ];
    }
}
