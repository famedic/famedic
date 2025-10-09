<?php

namespace App\Http\Controllers;

use App\Actions\OnlinePharmacy\CalculateTotalsAction;
use App\Enums\Gender;
use App\Http\Requests\OnlinePharmacy\CheckoutRequest;
use App\Models\Address;
use App\Services\Tracking\InitiateCheckout;
use Inertia\Inertia;

class OnlinePharmacyCheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, CalculateTotalsAction $calculateTotalsAction)
    {
        $onlinePharmacyCartItems = $request->user()->customer->onlinePharmacyCartItems()->get();
        $zipcode = Address::find($request->address)?->zipcode ?? $request->zipcode;
        $totals = $calculateTotalsAction($onlinePharmacyCartItems, $zipcode);

        InitiateCheckout::track(
            contents: [
                ...$onlinePharmacyCartItems->map(function ($item) {
                    return [
                        'id' => (string)$item->vitau_product_id,
                        'quantity' => $item->quantity
                    ];
                })->all(),
            ],
            value: floatval($totals['total'] ?: $totals['subtotal']),
            source: 'online-pharmacy',
        );

        $totals['total'] = $totals['total'] * 100;

        return Inertia::render('OnlinePharmacyCheckout', [
            ...$totals,
            'contacts' => $request->user()->customer->contacts,
            'genders' => Gender::casesWithLabels(),
            'addresses' => $request->user()->customer->addresses,
            'paymentMethods' => $request->user()->customer->paymentMethods(),
            'hasOdessaPay' => $request->user()->customer->has_odessa_afiliate_account,
            'mexicanStates' => config('mexicanstates'),
        ]);
    }
}
