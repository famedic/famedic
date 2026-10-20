<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $customer = $user->customer;

        return Inertia::render('Account', [
            'mustVerifyEmail' => ! $user->hasVerifiedEmail(),
            'mustVerifyPhone' => ! $user->phone_verified_at,
            'genders' => Gender::casesWithLabels(),
            'accountOverview' => [
                'addresses' => (int) ($customer?->addresses()->count() ?? 0),
                'payment_methods' => (int) ($customer
                    ? $customer->efevooTokens()->active()->excludeMockInProduction()->count()
                    : 0),
                'tax_profiles' => (int) ($customer?->taxProfiles()->count() ?? 0),
                'contacts' => (int) ($customer?->contacts()->count() ?? 0),
                'orders' => (int) ($customer
                    ? $customer->laboratoryPurchases()->count() + $customer->onlinePharmacyPurchases()->count()
                    : 0),
            ],
        ]);
    }
}
