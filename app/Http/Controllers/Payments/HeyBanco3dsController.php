<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\HeyBanco\CreateHeyBanco3dsTokenChargeSessionAction;
use App\Actions\Payments\HeyBanco\HandleHeyBanco3dsCallbackAction;
use App\Actions\Payments\HeyBanco\StartHeyBanco3dsTokenChargeAction;
use App\Exceptions\HeyBancoPaymentException;
use App\Http\Controllers\Controller;
use App\Models\Payment3dsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class HeyBanco3dsController extends Controller
{
    public function start(
        Request $request,
        CreateHeyBanco3dsTokenChargeSessionAction $createSessionAction,
        StartHeyBanco3dsTokenChargeAction $startSessionAction,
    ): JsonResponse {
        $validated = $request->validate([
            'payment_method_id' => ['required', 'string'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'checkout_context' => ['nullable', 'array'],
        ]);

        $customer = $request->user()->customer;

        try {
            $session = $createSessionAction(
                customer: $customer,
                paymentMethodId: $validated['payment_method_id'],
                amountCents: (int) $validated['amount_cents'],
                checkoutContext: $validated['checkout_context'] ?? [],
            );

            $result = $startSessionAction($session);

            return response()->json([
                'status' => 'requires_3ds_redirect',
                'redirect_url' => $result->redirectUrl,
                'payment_3ds_session_id' => $session->id,
            ]);
        } catch (HeyBancoPaymentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function callback(Request $request, HandleHeyBanco3dsCallbackAction $handleCallbackAction)
    {
        $payload = $request->all();

        Log::info('[HeyBanco3DS] Callback recibido', [
            'folio' => $payload['BNRG_FOLIO'] ?? $payload['bnrg_folio'] ?? null,
            'codigo_proc' => $payload['BNRG_CODIGO_PROC'] ?? $payload['bnrg_codigo_proc'] ?? null,
        ]);

        try {
            $result = $handleCallbackAction($payload);
            $session = $result['session'];

            if ($request->expectsJson()) {
                return response()->json(['status' => 'ok'], 200);
            }

            return redirect()->route('payments.hey-banco.3ds.result', [
                'session' => $session->id,
            ]);
        } catch (HeyBancoPaymentException $e) {
            Log::warning('[HeyBanco3DS] Callback rechazado', [
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['status' => 'error'], 200);
            }

            $folio = $payload['BNRG_FOLIO'] ?? null;
            $session = $folio
                ? Payment3dsSession::query()->where('folio', $folio)->first()
                : null;

            if ($session) {
                return redirect()->route('payments.hey-banco.3ds.result', [
                    'session' => $session->id,
                ]);
            }

            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('[HeyBanco3DS] Error procesando callback', [
                'error' => $e->getMessage(),
            ]);

            return response('OK', 200);
        }
    }

    public function redirectPage(Payment3dsSession $session): Response
    {
        $this->authorizeSession($session);

        return Inertia::render('Payments/HeyBanco3dsRedirect', [
            'sessionId' => $session->id,
            'redirectUrl' => $session->redirect_url,
        ]);
    }

    public function result(Request $request, Payment3dsSession $session): Response
    {
        $this->authorizeSession($session);

        return Inertia::render('Payments/HeyBanco3dsResult', [
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'bnrg_codigo_proc' => $session->bnrg_codigo_proc,
                'bnrg_text' => $session->bnrg_text,
                'amount' => $session->amount,
                'currency' => $session->currency,
                'purchase_id' => $session->fresh()->paymentTransaction
                    ?->related_id,
            ],
            'purchaseUrl' => $this->resolvePurchaseUrl($session),
        ]);
    }

    public function status(Request $request, Payment3dsSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        $finalStatuses = ['approved', 'declined', 'rejected', 'timeout', 'failed'];

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'bnrg_codigo_proc' => $session->bnrg_codigo_proc,
            'message' => $session->bnrg_text,
            'final' => in_array($session->status, $finalStatuses, true) || $session->isApproved(),
            'approved' => $session->isApproved(),
            'redirect_url' => $session->redirect_url,
        ]);
    }

    private function authorizeSession(Payment3dsSession $session): void
    {
        $user = auth()->user();

        if (! $user || ($session->user_id && $session->user_id !== $user->id)) {
            abort(403);
        }
    }

    private function resolvePurchaseUrl(Payment3dsSession $session): ?string
    {
        if (! $session->isApproved()) {
            return null;
        }

        $transaction = \App\Models\Transaction::query()
            ->where('gateway', config('heybanco.provider_key'))
            ->where('details->payment_details->payment_3ds_session_id', $session->id)
            ->first();

        $purchase = $transaction?->laboratoryPurchases()->first();

        if (! $purchase) {
            return null;
        }

        return route('laboratory-purchases.show', ['laboratory_purchase' => $purchase]);
    }
}
