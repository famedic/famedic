<?php

namespace Database\Factories;

use App\Models\Administrator;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaboratoryConciergeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'administrator_id' => Administrator::factory(),
        ];
    }
}
