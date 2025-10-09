<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use App\Services\Tracking\InitiateCheckout;
use Inertia\Inertia;

class LaboratoryCheckoutController extends Controller
{
    public function __invoke(Request $request, LaboratoryBrand $laboratoryBrand, CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction)
    {
        $laboratoryCartItems = $request->user()->customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->get();

        $totals = $calculateTotalsAndDiscountAction(
            $laboratoryCartItems
        );

        InitiateCheckout::track(
            contents: [
                ...$laboratoryCartItems->map(function ($item) {
                    return [
                        'id' => (string)$item->laboratoryTest->gda_id,
                        'quantity' => 1
                    ];
                })->all(),
            ],
            value: floatval(str_replace(',', '', formattedCents($totals['total']))),
            source: 'laboratory',
            customProperties: [
                'brand' => $laboratoryBrand->value,
            ]
        );

        return Inertia::render('LaboratoryCheckout', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            ...($request->user()->customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand) ?
                ['laboratoryAppointment' => $request->user()->customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand)] :
                []),
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
