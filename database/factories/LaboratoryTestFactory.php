<?php

namespace Database\Factories;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTestCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaboratoryTestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand' => fake()->randomElement(LaboratoryBrand::cases())->value,
            'gda_id' => fake()->unique()->word(),
            'name' => fake()->unique()->word(),
            'indications' => fake()->sentence(),
            'requires_appointment' => fake()->boolean(),
            'public_price_cents' => fake()->numberBetween(100, 10000),
            'famedic_price_cents' => fake()->numberBetween(100, 10000),
            'laboratory_test_category_id' => LaboratoryTestCategory::factory(),
        ];
    }
}
