<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    public function definition(): array
    {
        $state = fake()->randomElement(array_keys(config('mexicanstates')));
        $city = fake()->randomElement(config('mexicanstates.' . $state));

        return [
            'street' => fake()->streetName(),
            'number' => (string)fake()->numberBetween(1, 999),
            'neighborhood' => fake()->word(),
            'state' => $state,
            'city' => $city,
            'zipcode' => fake()->postcode(),
            'additional_references' => fake()->sentence(),
            'customer_id' => Customer::factory(),
        ];
    }
}
