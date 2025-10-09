<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OdessaAfiliatedCompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'odessa_identifier' => fake()->uuid()
        ];
    }
}
