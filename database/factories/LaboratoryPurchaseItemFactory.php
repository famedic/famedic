<?php

namespace Database\Factories;

use App\Models\LaboratoryPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaboratoryPurchaseItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'gda_id' => $this->numberBetween(100, 100000),
            'indications' => $this->faker->sentence(),
            'price_cents' => $this->faker->numberBetween(100, 100000),
            'laboratory_purchase_id' => LaboratoryPurchase::factory(),
        ];
    }
}
