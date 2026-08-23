<?php

namespace Database\Factories;

use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentAuthenticationRecoveryContextFactory extends Factory
{
    protected $model = PaymentAuthenticationRecoveryContext::class;

    public function definition(): array
    {
        return [
            'context_uuid' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'context_type' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings,
            'status' => PaymentAuthenticationRecoveryContextStatus::Open,
            'return_route_name' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->returnRouteName(),
            'context_data' => [],
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function laboratory(): static
    {
        return $this->state(fn () => [
            'context_type' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout,
            'return_route_name' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->returnRouteName(),
            'context_data' => [
                'laboratory_brand' => 'olab',
                'step' => 'payment',
            ],
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => PaymentAuthenticationRecoveryContextStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
