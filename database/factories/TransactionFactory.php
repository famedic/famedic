<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_amount_cents' => fake()->numberBetween(100, 10000),
            'payment_method' => fake()->randomElement(['stripe', 'odessa']),
            'reference_id' => fake()->uuid,
        ];
    }
}
