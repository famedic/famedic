<?php

namespace App\Http\Controllers;

use App\Actions\MedicalAttention\PurchaseRegularSubscriptionAction;
use App\Exceptions\MurguiaConflictException;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Http\Requests\MedicalAttention\MedicalAttentionSubscriptionRequest;
use Stripe\Exception\CardException;

class MedicalAttentionSubscriptionController extends Controller
{
    public function __invoke(MedicalAttentionSubscriptionRequest $request, PurchaseRegularSubscriptionAction $purchaseAction)
    {
        try {
            $purchaseAction($request->user()->customer, $request->payment_method, $request->total);
            return redirect()->route('medical-attention')
                ->with('confetti', true)
                ->flashMessage('Tu suscripción de atención médica ha comenzado exitosamente.');
        } catch (UnmatchingTotalPriceException $e) {
            return redirect()->back()->withErrors(['total' => 'El precio ha cambiado. Por favor actualiza la página.']);
        } catch (CardException $e) {
            return redirect()->back()->withErrors(['payment_method' => 'No pudimos procesar tu pago. Por favor verifica la información de tu método de pago.']);
        } catch (OdessaInsufficientFundsException $e) {
            return redirect()->back()->withErrors(['payment_method' => 'No cuentas con suficiente Saldo a la Vista para realizar el pago.']);
        } catch (MurguiaConflictException $e) {
            // The PurchaseRegularSubscriptionAction should handle transaction rollback and refund
            return redirect()->back()->flashMessage('No se pudo completar la suscripción. Se ha procesado el reembolso. Por favor contacta soporte.', 'error');
        }
    }
}
