<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class OnlinePharmacyCartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quantity' => fake()->numberBetween(1, 10),
            'vitau_product_id' => fake()->numberBetween(100000, 999999),
            'customer_id' => Customer::factory(),
        ];
    }
}
