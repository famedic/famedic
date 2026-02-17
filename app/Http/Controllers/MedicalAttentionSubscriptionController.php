<?php

namespace App\Http\Controllers;

use App\Actions\MedicalAttention\PurchaseRegularSubscriptionAction;
use App\Exceptions\MurguiaConflictException;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Exceptions\EfevooPaymentException;
use App\Http\Requests\MedicalAttention\MedicalAttentionSubscriptionRequest;
use Illuminate\Support\Facades\Log;

class MedicalAttentionSubscriptionController extends Controller
{
    public function __invoke(
        MedicalAttentionSubscriptionRequest $request,
        PurchaseRegularSubscriptionAction $purchaseAction
    ) {

        Log::info('🟢 [STEP 1] Controller reached - MedicalAttentionSubscriptionController');

        Log::info('🟢 [STEP 2] Request basic data', [
            'user_id' => optional($request->user())->id,
            'customer_id' => optional($request->user()?->customer)->id,
            'payment_method' => $request->payment_method,
            'total' => $request->total,
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        try {

            Log::info('🟡 [STEP 3] About to execute PurchaseRegularSubscriptionAction');

            $purchaseAction(
                $request->user()->customer,
                $request->payment_method,
                config('famedic.medical_attention_subscription_price_cents')
            );

            Log::info('🟢 [STEP 4] Subscription action finished successfully');

            return redirect()
                ->route('medical-attention')
                ->with('confetti', true)
                ->flashMessage('Tu suscripción de atención médica ha comenzado exitosamente.');

        } catch (\Throwable $e) {

            Log::error('🔴 [ERROR] Exception caught in controller', [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return redirect()->back()
                ->withErrors(['general' => 'Ocurrió un error durante la suscripción.']);
        } finally {

            Log::info('⚫ [FINAL] Controller execution ended');
        }
    }

}
