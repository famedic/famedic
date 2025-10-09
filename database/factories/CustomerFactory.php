<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medical_attention_identifier' => fake()->numberBetween(100000, 999999),
        ];
    }

    public function withActiveSubscription()
    {
        return $this->state(fn(array $attributes) => [
            'medical_attention_subscription_expires_at' => now()->addDays(30),
        ]);
    }

    public function withRegularAccount()
    {
        return $this->for(
            RegularAccount::factory(),
            'customerable'
        );
    }

    public function withOdessaAfiliateAccount()
    {
        return $this->for(
            OdessaAfiliateAccount::factory(),
            'customerable'
        );
    }

    public function withFamilyAccount(Customer $parentCustomer)
    {
        return $this->state(function (array $attributes) use ($parentCustomer) {
            return [
                'medical_attention_subscription_expires_at' => $parentCustomer->medical_attention_subscription_expires_at,
            ];
        })->for(
            FamilyAccount::factory()->for(
                $parentCustomer,
                'parentCustomer'
            ),
            'customerable'
        );
    }
}
