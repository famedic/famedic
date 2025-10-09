<?php

namespace Database\Factories;

use App\Actions\GeneratePhoneNumberAction;
use App\Enums\Gender;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        $phone ??= app(GeneratePhoneNumberAction::class)();

        return [
            'name' => fake()->name(),
            'paternal_lastname' => fake()->lastName(),
            'maternal_lastname' => fake()->lastName(),
            'phone' => str_replace(' ', '', $phone->formatNational()),
            'phone_country' => $phone->getCountry(),
            'birth_date' => fake()->date(),
            'gender' => fake()->randomElement(Gender::cases())->value,
            'customer_id' => Customer::factory(),
        ];
    }
}
