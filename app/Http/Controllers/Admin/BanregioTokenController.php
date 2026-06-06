<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use Inertia\Inertia;

class BanregioTokenController extends Controller
{
    public function show(PaymentMethod $paymentMethod)
    {
        request()->user()->administrator->hasPermissionTo('banregio.manage') || abort(403);

        if ($paymentMethod->provider !== config('heybanco.provider_key')) {
            abort(404);
        }

        $paymentMethod->load([
            'user.customer',
            'transactions' => fn ($query) => $query->latest()->limit(50),
            'createdFromTransaction',
        ]);

        $paymentMethod->customer = $paymentMethod->user?->customer;
        $paymentMethod->is_expired = $paymentMethod->isExpired();
        $paymentMethod->masked_provider_token = $this->maskToken($paymentMethod->provider_token);

        $attempts = PaymentAttempt::query()
            ->where('gateway', config('heybanco.provider_key'))
            ->where('token_id', $paymentMethod->id)
            ->latest('processed_at')
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('Admin/BanregioToken', [
            'token' => $paymentMethod,
            'attempts' => $attempts,
            'config' => [
                'environment' => config('heybanco.env'),
                'mode' => config('heybanco.mode'),
                'adq_url' => config('heybanco.adq_url'),
            ],
        ]);
    }

    private function maskToken(?string $value, int $visibleChars = 8): ?string
    {
        if (! $value) {
            return null;
        }

        if (strlen($value) <= $visibleChars) {
            return $value;
        }

        return '...' . substr($value, -$visibleChars);
    }
}
