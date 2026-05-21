<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Services\CouponService;
use App\Support\AppEnvironmentLabel;
use App\Support\MockEfevooPaymentSupport;
use Illuminate\Http\Request;
use App\Services\Tracking\InitiateCheckout;
use Inertia\Inertia;

class LaboratoryCheckoutController extends Controller
{
    public function __invoke(Request $request, LaboratoryBrand $laboratoryBrand, CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction, CouponService $couponService)
    {
        $laboratoryCartItems = $request->user()->customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->get();

        $totals = $calculateTotalsAndDiscountAction(
            $laboratoryCartItems
        );

        $userId = $request->user()->id;
        $customer = $request->user()->customer;
        $balanceCents = $couponService->getUserBalance($userId);
        $availableCoupons = $couponService->getAvailableCoupons($userId);
        $mockTokens = MockEfevooPaymentSupport::isMockMode()
            ? MockEfevooPaymentSupport::ensureTestTokensForCustomer($customer)
            : [];
        $paymentMethods = $this->resolveCheckoutPaymentMethods($customer, $mockTokens);

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
            'balanceCouponsCents' => $balanceCents,
            'formattedBalanceCoupons' => $balanceCents > 0 ? formattedCentsPrice($balanceCents) : null,
            'availableBalanceCoupons' => $availableCoupons,
            'hasPayPal' => (bool) config('services.paypal.client_id'),
            'paypalClientId' => config('services.paypal.client_id'),
            'contacts' => $request->user()->customer->contacts,
            'genders' => Gender::casesWithLabels(),
            'addresses' => $request->user()->customer->addresses,
            'paymentMethods' => $paymentMethods,
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'defaultMockPaymentMethodId' => $mockTokens[0]['id'] ?? null,
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
            'hasOdessaPay' => $request->user()->customer->has_odessa_afiliate_account,
            'mexicanStates' => config('mexicanstates'),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mockTokens
     * @return array<int, array<string, mixed>>
     */
    private function resolveCheckoutPaymentMethods(\App\Models\Customer $customer, array $mockTokens = []): array
    {
        $userTokens = $customer->efevooTokens()
            ->active()
            ->excludeMockInProduction()
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'object' => 'efevoo_token',
                    'card' => [
                        'brand' => strtolower($token->card_brand),
                        'last4' => $token->card_last_four,
                        'exp_month' => substr($token->card_expiration, 0, 2),
                        'exp_year' => '20'.substr($token->card_expiration, 2, 2),
                        'exp_year_short' => substr($token->card_expiration, 2, 2),
                    ],
                    'billing_details' => [
                        'name' => $token->card_holder,
                    ],
                    'alias' => $token->alias ?? $token->generateAlias(),
                    'metadata' => [
                        'environment' => $token->environment,
                        'mock' => (bool) ($token->metadata['mock'] ?? false),
                        'expires_at' => $token->expires_at?->toISOString(),
                    ],
                ];
            })
            ->values()
            ->all();

        if ($mockTokens === []) {
            return $userTokens;
        }

        return MockEfevooPaymentSupport::mergePaymentMethodsForCheckout($userTokens, $mockTokens);
    }
}
