<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class MedicalAttentionController extends Controller
{
    public function __invoke(Request $request)
    {
        $medicalAttentionSubscriptionIsActive = $request->user()?->customer?->medical_attention_subscription_is_active ?? false;

        return Inertia::render('MedicalAttention', [
            ...($request->user() && $medicalAttentionSubscriptionIsActive ? [
                'familyAccounts' => $request->user()->customer->familyAccounts()->with('customer.user')->get(),
            ] : []),
            ...($request->user() && !$medicalAttentionSubscriptionIsActive ? [
                'paymentMethods' => $request->user()->customer->paymentMethods(),
            ] : []),
            'formattedPrice' => formattedCentsPrice(config('famedic.medical_attention_subscription_price_cents')),
        ]);
    }
}
