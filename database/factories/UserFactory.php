<?php

namespace Database\Factories;

use App\Actions\GeneratePhoneNumberAction;
use App\Enums\Gender;
use App\Models\Administrator;
use App\Models\Customer;
use App\Models\LaboratoryConcierge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Propaganistas\LaravelPhone\PhoneNumber;

class UserFactory extends Factory
{
    // The current password being used by the factory.
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'paternal_lastname' => fake()->lastName(),
            'maternal_lastname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'birth_date' => fake()->date(),
            'gender' => fake()->randomElement(Gender::cases())->value,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function withCompleteProfile(?PhoneNumber $phone = null): static
    {
        return $this->withVerifiedPhone($phone)->withVerifiedEmail();
    }

    public function withVerifiedPhone(?PhoneNumber $phone = null): static
    {
        $phone ??= app(GeneratePhoneNumberAction::class)();

        return $this->state(fn(array $attributes) => [
            'phone' => str_replace(' ', '', $phone->formatNational()),
            'phone_country' => $phone->getCountry(),
            'phone_verified_at' => now(),
        ]);
    }

    public function withUnverifiedEmail(): static
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withVerifiedEmail(): static
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => now(),
        ]);
    }

    public function withRegularCustomer(): static
    {
        return $this->has(
            Customer::factory()->withRegularAccount()
        );
    }

    public function withLaboratoryConcierge(): static
    {
        return $this->has(LaboratoryConcierge::factory());
    }

    public function withAdministrator(): static
    {
        return $this->has(Administrator::factory());
    }
}
