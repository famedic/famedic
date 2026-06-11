<?php

namespace App\Actions\Api\V1\Auth;

use App\Actions\Register\RegisterRegularCustomerAction;
use App\Models\User;
use Illuminate\Support\Str;

class RegisterAkubicaCustomerAction
{
    public function __construct(
        private RegisterRegularCustomerAction $registerRegularCustomerAction,
    ) {}

    /**
     * @param  array{email: string, phone: string, full_name: string, phone_country?: string}  $payload
     */
    public function __invoke(array $payload): User
    {
        $phoneCountry = $payload['phone_country'] ?? 'MX';
        $phone = preg_replace('/\s+/', '', $payload['phone']);
        $name = trim($payload['full_name']);

        $regularAccount = ($this->registerRegularCustomerAction)(
            email: $payload['email'],
            name: $name,
            phone: $phone,
            phoneCountry: $phoneCountry,
            password: Str::password(32),
        );

        $regularAccount->load('customer.user');

        $user = $regularAccount->customer->user;
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh(['customer']);
    }
}
