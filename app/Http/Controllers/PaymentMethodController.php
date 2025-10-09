<?php

namespace App\Http\Controllers;

use App\Actions\Stripe\CreateStripeCheckoutSessionAction;
use App\Actions\Stripe\DeleteStripePaymentMethodAction;
use App\Http\Requests\PaymentMethods\DestroyPaymentMethodRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('success')) {
            return redirect()->route('payment-methods.index')
                ->flashMessage('Tarjeta guardada exitosamente.');
        }
        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $request->user()->customer->paymentMethods(),
            'hasOdessaPay' => $request->user()->customer->has_odessa_afiliate_account,
        ]);
    }

    public function create(Request $request, CreateStripeCheckoutSessionAction $action)
    {
        return Inertia::location(
            $action(
                $request->user()->customer->stripe_customer,
                $request->return_url ?? route('payment-methods.index', ['success' => true]),
                $request->return_url ?? route('payment-methods.index')
            )->url
        );
    }

    public function destroy(DestroyPaymentMethodRequest $request, string $paymentMethod, DeleteStripePaymentMethodAction $action)
    {
        $action($request->user()->customer, $paymentMethod);

        return back()->flashMessage('Tarjeta eliminada exitosamente.');
    }
}
