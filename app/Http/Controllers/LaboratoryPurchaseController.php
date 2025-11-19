<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\OrderAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreLaboratoryPurchaseRequest;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Services\Tracking\Purchase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stripe\Exception\CardException;

class LaboratoryPurchaseController extends Controller
{
    public function store(StoreLaboratoryPurchaseRequest $request, LaboratoryBrand $laboratoryBrand, OrderAction $orderAction)
    {
        try {
            $laboratoryPurchase = $orderAction(
                customer: $request->user()->customer,
                address: Address::find($request->address),
                contact: Contact::find($request->contact),
                paymentMethod: $request->payment_method,
                laboratoryBrand: $laboratoryBrand,
                totalCents: $request->total,
            );
        } catch (CardException $e) {
            return redirect()->back()
                ->withErrors(['payment_method' => 'No pudimos procesar tu pago. Por favor verifica la información de tu método de pago o intenta con uno diferente.']);
        } catch (OdessaInsufficientFundsException $e) {
            return redirect()->back()
                ->withErrors(['payment_method' => 'No cuentas con suficiente Saldo a la Vista en tu caja de ahorro para realizar el pago.']);
        }

        session()->flash('confetti', true);

        Purchase::track(
            purchaseId: $laboratoryPurchase->id,
            contents: $laboratoryPurchase->laboratoryPurchaseItems->map(function ($item) {
                return [
                    'id' => $item->gda_id,
                    'quantity' => 1,
                ];
            })->all(),
            value: $laboratoryPurchase->total,
            source: 'laboratory',
            customProperties: [
                'laboratory_brand' => $laboratoryBrand->value,
            ]
        );

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Pedido realizado con éxito.');
    }

    public function index(Request $request)
    {
        $filters = $request->only('search');

        return Inertia::render('LaboratoryPurchases', [
            'laboratoryPurchases' => $request->user()->customer->laboratoryPurchases()
                ->filter($filters)
                ->with(['transactions', 'laboratoryPurchaseItems', 'invoice', 'invoiceRequest'])
                ->latest()
                ->get(),
            'laboratoryQuotes' => $request->user()->customer->laboratoryQuotes()
                ->filter($filters)
                ->with(['appointment', 'user'])
                ->latest()
                ->get(),
        ]);
    }

    public function show(Request $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $lastDayOfPurchaseMonth = localizedDate($laboratoryPurchase->created_at)->endOfMonth();
        $nowInMonterrey = localizedDate(now());

        return Inertia::render('LaboratoryPurchase', [
            'laboratoryPurchase' => $laboratoryPurchase->load(['transactions', 'laboratoryPurchaseItems', 'laboratoryAppointment.laboratoryStore', 'invoiceRequest', 'invoice']),
            'taxProfiles' => auth()->user()->customer->taxProfiles,
            'daysLeftToRequestInvoice' => $nowInMonterrey->lt($lastDayOfPurchaseMonth)
                ? (int)ceil($nowInMonterrey->diffInDays($lastDayOfPurchaseMonth, false))
                : 0,
            ...session()->get('confetti') ? ['confetti' => true] : [],
        ]);
    }
}