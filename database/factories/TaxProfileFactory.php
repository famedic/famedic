<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TaxProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'rfc' => fake()->unique()->uuid(),
            'zipcode' => fake()->postcode(),
            'tax_regime' => fake()->sentence(),
            'cfdi_use' => fake()->sentence(),
        ];
    }
}
