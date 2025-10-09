<?php

namespace Database\Factories;

use App\Models\OnlinePharmacyPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

class OnlinePharmacyPurchaseItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'vitau_product_id' => $this->numberBetween(100, 100000),
            'indications' => $this->faker->sentence(),
            'price_cents' => $this->faker->numberBetween(100, 100000),
            'online_pharmacy_purchase_id' => OnlinePharmacyPurchase::factory(),
        ];
    }
}
