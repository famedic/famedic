<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Actions\Laboratories\SyncLaboratoryAppointmentFromContactAction;
use App\Enums\Gender;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Enums\LaboratoryBrand;
use App\Http\Requests\LaboratoryCheckout\SyncLaboratoryAppointmentRequest;
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

        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand);
        $laboratoryAppointment = null;
        $pendingLaboratoryAppointment = null;
        $callbackPreferenceSavedAtFormatted = null;

        if ($requiresAppointment) {
            $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand);

            if (! $laboratoryAppointment) {
                $pendingLaboratoryAppointment = $customer->getPendingLaboratoryAppointment($laboratoryBrand);
                $callbackPreferenceSavedAtFormatted = $this->formatCallbackPreferenceSavedAt(
                    $pendingLaboratoryAppointment
                );
            }
        }

        return Inertia::render('LaboratoryCheckout', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            'requiresAppointment' => $requiresAppointment,
            'laboratoryAppointment' => $laboratoryAppointment,
            'pendingLaboratoryAppointment' => $pendingLaboratoryAppointment,
            'callbackPreferenceSavedAtFormatted' => $callbackPreferenceSavedAtFormatted,
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

    public function syncAppointment(
        SyncLaboratoryAppointmentRequest $request,
        LaboratoryBrand $laboratoryBrand,
        SyncLaboratoryAppointmentFromContactAction $action,
    ) {
        $customer = $request->user()->customer;

        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
            abort(422, 'El carrito no requiere cita.');
        }

        $contact = $customer->contacts()->findOrFail($request->validated('contact_id'));
        $action($customer, $laboratoryBrand, $contact);

        $query = array_filter([
            'step' => 'appointment',
            'contact' => $request->validated('contact_id'),
            'address' => $request->input('address'),
            'payment_method' => $request->input('payment_method'),
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()->route('laboratory.checkout', [
            'laboratory_brand' => $laboratoryBrand,
            ...$query,
        ]);
    }

    private function formatCallbackPreferenceSavedAt($appointment): ?string
    {
        if (! $appointment) {
            return null;
        }

        $lastPreferenceInteraction = $appointment->interactions()
            ->where('type', LaboratoryAppointmentInteractionType::PatientCallbackPreference->value)
            ->latest('id')
            ->first();

        return $lastPreferenceInteraction?->created_at
            ?->timezone(config('app.timezone'))
            ?->locale('es')
            ?->isoFormat('dddd D [de] MMMM [de] YYYY, h:mm a');
    }
}
