<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\HeyBanco\ChargeHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\CreateHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\VerifyHeyBancoTransactionAction;
use App\Exceptions\HeyBancoPaymentException;
use App\Http\Controllers\Controller;
use App\Support\PaymentMethodIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeyBancoPaymentController extends Controller
{
    public function tokenize(Request $request, CreateHeyBancoTokenAction $action): JsonResponse
    {
        abort_unless(config('heybanco.enabled'), 403, 'Hey Banco no está habilitado.');

        $validated = $request->validate([
            'card_number' => 'required|string',
            'exp_month' => 'required|string',
            'exp_year' => 'required|string',
            'cvv' => 'required|string',
            'card_holder' => 'nullable|string',
            'alias' => 'nullable|string',
        ]);

        try {
            $paymentMethod = $action($request->user(), $validated);

            return response()->json([
                'success' => true,
                'payment_method' => [
                    'id' => PaymentMethodIdentifier::heyBancoPublicId($paymentMethod->id),
                    'provider' => $paymentMethod->provider,
                    'brand' => $paymentMethod->brand,
                    'last4' => $paymentMethod->last4,
                    'alias' => $paymentMethod->alias,
                ],
            ]);
        } catch (HeyBancoPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->processorCode,
            ], 422);
        }
    }

    public function charge(Request $request, ChargeHeyBancoTokenAction $action): JsonResponse
    {
        abort_unless(config('heybanco.enabled'), 403, 'Hey Banco no está habilitado.');

        $validated = $request->validate([
            'payment_method_id' => 'required|string',
            'amount_cents' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:50',
        ]);

        $customer = $request->user()->customer;

        try {
            $transaction = $action(
                customer: $customer,
                amountCents: $validated['amount_cents'],
                paymentMethodId: $validated['payment_method_id'],
                reference: $validated['reference'] ?? null,
            );

            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'gateway' => $transaction->gateway,
                    'gateway_transaction_id' => $transaction->gateway_transaction_id,
                    'authorization_code' => $transaction->gateway_authorization_code,
                    'amount_cents' => $transaction->transaction_amount_cents,
                ],
            ]);
        } catch (HeyBancoPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->processorCode,
            ], 422);
        }
    }

    public function verify(Request $request, VerifyHeyBancoTransactionAction $action): JsonResponse
    {
        abort_unless(config('heybanco.enabled'), 403, 'Hey Banco no está habilitado.');

        $validated = $request->validate([
            'reference' => 'nullable|string',
            'folio' => 'nullable|string|max:12',
            'media_id' => 'nullable|string',
        ]);

        if (empty($validated['reference']) && empty($validated['folio'])) {
            return response()->json([
                'success' => false,
                'message' => 'Debes enviar reference o folio.',
            ], 422);
        }

        try {
            $verification = ! empty($validated['folio'])
                ? $action->byFolio(
                    user: $request->user(),
                    folio: $validated['folio'],
                    mediaId: $validated['media_id'] ?? null,
                )
                : $action->byReference(
                    user: $request->user(),
                    reference: $validated['reference'],
                    mediaId: $validated['media_id'] ?? null,
                );

            return response()->json([
                'success' => true,
                'approved' => $verification->status === 'approved',
                'verification' => $verification,
            ]);
        } catch (HeyBancoPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->processorCode,
            ], 422);
        }
    }
}
