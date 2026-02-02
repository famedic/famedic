<?php
// database/factories/EfevooTokenFactory.php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EfevooTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_token' => base64_encode(fake()->sha256()),
            'card_token' => base64_encode(fake()->sha256()),
            'card_last_four' => fake()->numerify('####'),
            'card_brand' => fake()->randomElement(['Visa', 'MasterCard', 'American Express']),
            'card_expiration' => fake()->numerify('##') . fake()->numberBetween(25, 30),
            'card_holder' => fake()->name(),
            'client_id' => 1,
            'environment' => 'test',
            'expires_at' => now()->addYear(),
            'is_active' => true,
            'metadata' => null,
        ];
    }
}