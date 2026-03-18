<?php

namespace App\Http\Controllers;

use App\Actions\OnlinePharmacy\OrderAction;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyPurchases\StoreOnlinePharmacyPurchaseRequest;
use App\Models\Address;
use App\Models\Contact;
use App\Models\OnlinePharmacyPurchase;
use App\Services\Tracking\Purchase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stripe\Exception\CardException;

class OnlinePharmacyPurchaseController extends Controller
{
    public function store(StoreOnlinePharmacyPurchaseRequest $request, OrderAction $orderAction)
    {
        try {
            $onlinePharmacyPurchase = $orderAction(
                customer: $request->user()->customer,
                address: Address::find($request->address),
                contact: Contact::find($request->contact),
                paymentMethod: $request->payment_method,
                totalCents: $request->total,
            );
        } catch (CardException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Your card has insufficient funds')) {
                $message = 'La tarjeta no tiene fondos suficientes.';
            } elseif (str_contains($message, 'Your card was declined')) {
                $message = 'La tarjeta fue rechazada. Por favor verifica con tu banco o intenta con otra tarjeta.';
            } elseif (str_contains($message, 'expired')) {
                $message = 'La tarjeta ha expirado. Usa otro método de pago.';
            } elseif (! str_contains($message, 'La ') && ! str_contains($message, 'El ')) {
                $message = 'No pudimos procesar tu pago. Por favor verifica tu método de pago o intenta con otro.';
            }
            return redirect()->back()
                ->withErrors(['payment_method' => $message]);
        } catch (OdessaInsufficientFundsException $e) {
            return redirect()->back()
                ->withErrors(['payment_method' => 'No cuentas con suficiente Saldo a la Vista en tu caja de ahorro para realizar el pago.']);
        }

        session()->flash('confetti', true);

        Purchase::track(
            purchaseId: $onlinePharmacyPurchase->id,
            contents: $onlinePharmacyPurchase->onlinePharmacyPurchaseItems->map(function ($item) {
                return [
                    'id' => $item->vitau_product_id,
                    'quantity' => $item->quantity,
                ];
            })->all(),
            value: $onlinePharmacyPurchase->total,
            source: 'online-pharmacy',
        );

        return redirect()->route('online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Pedido realizado con éxito.');
    }

    public function index(Request $request)
    {
        return Inertia::render('OnlinePharmacyPurchases', [
            'onlinePharmacyPurchases' => $request->user()->customer->onlinePharmacyPurchases()->with(['transactions', 'onlinePharmacyPurchaseItems', 'invoiceRequest', 'invoice'])->latest()->get(),
        ]);
    }

    public function show(Request $request, OnlinePharmacyPurchase $onlinePharmacyPurchase)
    {
        return Inertia::render('OnlinePharmacyPurchase', [
            'onlinePharmacyPurchase' => $onlinePharmacyPurchase->load(['transactions', 'onlinePharmacyPurchaseItems', 'invoiceRequest', 'invoice']),
            'taxProfiles' => auth()->user()->customer->taxProfiles,
            'daysLeftToRequestInvoice' => now()->lt($onlinePharmacyPurchase->created_at->addDays(30))
                ? (int)now()->diffInDays($onlinePharmacyPurchase->created_at->addDays(30))
                : 0,
            ...session()->get('confetti') ? ['confetti' => true] : [],
        ]);
    }
}
