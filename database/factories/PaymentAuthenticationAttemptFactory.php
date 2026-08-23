<?php

namespace Database\Factories;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Customer;
use App\Models\PaymentAuthenticationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentAuthenticationAttemptFactory extends Factory
{
    protected $model = PaymentAuthenticationAttempt::class;

    public function definition(): array
    {
        return [
            'attempt_uuid' => (string) Str::uuid(),
            'support_reference' => 'AUTH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'customer_id' => Customer::factory(),
            'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
            'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
            'status' => PaymentAuthenticationAttemptStatus::Created->value,
            'merchant_reference' => 'EFV3DS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'attempt_number' => 1,
            'initiated_by' => 'customer',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
