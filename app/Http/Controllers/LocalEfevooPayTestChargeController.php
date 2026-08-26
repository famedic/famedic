<?php

namespace App\Http\Controllers;

use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Exceptions\EfevooPaymentException;
use App\Support\EfevooPayLocalRealTestMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalEfevooPayTestChargeController extends Controller
{
    public function charge(Request $request, ChargeEfevooPaymentMethodAction $chargeAction): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(EfevooPayLocalRealTestMode::activeFor($request->user()), 403);

        $validated = $request->validate([
            'token_id' => 'required|integer',
        ]);

        $customer = $request->user()->customer;
        $amountCents = (int) config('efevoopay.local_real_tests.payment_fixture_cents', 1000);
        $validation = EfevooPayLocalRealTestMode::validatePaymentAmountCents($amountCents, $amountCents);

        if (! $validation['allowed']) {
            return response()->json([
                'success' => false,
                'reason' => $validation['reason'],
            ], 422);
        }

        try {
            $transaction = $chargeAction(
                $customer,
                $amountCents,
                (string) $validated['token_id'],
                null,
                ['local_isolated_test' => true]
            );

            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->id,
                'amount_cents' => $amountCents,
            ]);
        } catch (EfevooPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
