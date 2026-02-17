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
                        'id' => (string) $item->laboratoryTest->gda_id,
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
            'paymentMethods' => $request->user()->customer->efevooTokens()
                ->active()
                ->get()
                ->map(function ($token) {
                    return [
                        'id' => $token->id,
                        'object' => 'efevoo_token',
                        'card' => [
                            'brand' => strtolower($token->card_brand),
                            'last4' => $token->card_last_four,
                            'exp_month' => substr($token->card_expiration, 0, 2),
                            'exp_year' => '20' . substr($token->card_expiration, 2, 2),
                            'exp_year_short' => substr($token->card_expiration, 2, 2),
                        ],
                        'billing_details' => [
                            'name' => $token->card_holder,
                        ],
                        'alias' => $token->alias ?? $token->generateAlias(),
                        'metadata' => [
                            'environment' => $token->environment,
                            'expires_at' => $token->expires_at?->toISOString(),
                        ]
                    ];
                })->toArray(),
            'hasOdessaPay' => $request->user()->customer->has_odessa_afiliate_account,
            'mexicanStates' => config('mexicanstates'),
        ]);
    }
}
