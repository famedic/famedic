<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use Inertia\Inertia;

class BanregioTransactionController extends Controller
{
    public function show(PaymentTransaction $paymentTransaction)
    {
        request()->user()->administrator->hasPermissionTo('banregio.manage') || abort(403);

        if ($paymentTransaction->provider !== config('heybanco.provider_key')) {
            abort(404);
        }

        $paymentTransaction->load(['user.customer', 'paymentMethod']);

        $paymentTransaction->customer = $paymentTransaction->user?->customer;

        $relatedAttempts = PaymentAttempt::query()
            ->where('gateway', config('heybanco.provider_key'))
            ->where(function ($query) use ($paymentTransaction) {
                $query->where('processor_transaction_id', $paymentTransaction->reference)
                    ->orWhere('reference', $paymentTransaction->reference);
            })
            ->latest('processed_at')
            ->latest('id')
            ->limit(20)
            ->get();

        return Inertia::render('Admin/BanregioTransaction', [
            'transaction' => $paymentTransaction,
            'relatedAttempts' => $relatedAttempts,
            'config' => [
                'environment' => config('heybanco.env'),
                'mode' => config('heybanco.mode'),
                'adq_url' => config('heybanco.adq_url'),
            ],
        ]);
    }
}
