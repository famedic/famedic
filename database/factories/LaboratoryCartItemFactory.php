<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LaboratoryTest;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaboratoryCartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'laboratory_test_id' => LaboratoryTest::factory(),
        ];
    }
}
