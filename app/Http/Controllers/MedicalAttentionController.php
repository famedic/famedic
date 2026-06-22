<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class MedicalAttentionController extends Controller
{
    public function __invoke(Request $request)
    {
        $medicalAttentionSubscriptionIsActive = $request->user()?->customer?->medical_attention_subscription_is_active ?? false;

        $props = [
            'formattedPrice' => formattedCentsPrice(config('famedic.medical_attention_subscription_price_cents')),
        ];

        if ($request->user() && $medicalAttentionSubscriptionIsActive) {
            $props['familyAccounts'] = $request->user()->customer->familyAccounts()->with('customer.user')->get();
        }

        return Inertia::render('MedicalAttention/Index', [
            ...$props,
            ...(session()->get('confetti') ? ['confetti' => true] : []),
        ]);
    }
}
