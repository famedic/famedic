<?php

namespace Database\Factories;

use App\Enums\Kinship;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'paternal_lastname' => fake()->lastName(),
            'maternal_lastname' => fake()->lastName(),
            'kinship' => fake()->randomElement(Kinship::cases()),
        ];
    }
}
