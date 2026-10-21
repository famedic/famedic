<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\MedicalServiceCart;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicalServiceCartFactory extends Factory
{
    protected $model = MedicalServiceCart::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'service_line' => MedicalServiceCart::SERVICE_LINE,
            'status' => MedicalServiceCart::STATUS_ACTIVE,
            'subtotal_cents' => 0,
            'discount_cents' => 0,
            'credit_applied_cents' => 0,
            'total_cents' => 0,
        ];
    }
}
