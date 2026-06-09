<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Actions\Laboratories\SyncLaboratoryCheckoutDraftAction;
use App\Actions\Laboratories\SyncLaboratoryAppointmentFromContactAction;
use App\Http\Requests\LaboratoryCheckout\SyncLaboratoryCheckoutDraftRequest;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
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
        $mockTokens = MockEfevooPaymentSupport::isMockMode() && config('payments.efevoopay_enabled', true)
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

        $savedCheckout = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $laboratoryBrand)
            ->first()
            ?->forCheckout();

        if ($requiresAppointment && ! $laboratoryAppointment && ! $pendingLaboratoryAppointment) {
            $pendingLaboratoryAppointment = $this->ensurePendingLaboratoryAppointment(
                $customer,
                $laboratoryBrand,
                $savedCheckout,
                $request,
            );

            if ($pendingLaboratoryAppointment) {
                $callbackPreferenceSavedAtFormatted = $this->formatCallbackPreferenceSavedAt(
                    $pendingLaboratoryAppointment
                );
            }
        }

        return Inertia::render('LaboratoryCheckout', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            'savedCheckout' => $savedCheckout,
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
            'heyBancoEnabled' => (bool) config('heybanco.enabled', false),
            'heyBanco3dsEnabled' => (bool) config('heybanco.3ds_enabled', false),
            'efevoopayEnabled' => (bool) config('payments.efevoopay_enabled', true),
            'defaultPaymentProvider' => app(\App\Services\Payments\PaymentGatewayManager::class)->defaultProvider(),
            'mexicanStates' => config('mexicanstates'),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mockTokens
     * @return array<int, array<string, mixed>>
     */
    private function resolveCheckoutPaymentMethods(\App\Models\Customer $customer, array $mockTokens = []): array
    {
        $userTokens = $customer->paymentMethods()
            ->map(function ($paymentMethod) {
                return [
                    'id' => $paymentMethod->id,
                    'object' => $paymentMethod->object,
                    'provider' => $paymentMethod->provider ?? null,
                    'card' => [
                        'brand' => $paymentMethod->card->brand,
                        'last4' => $paymentMethod->card->last4,
                        'exp_month' => $paymentMethod->card->exp_month,
                        'exp_year' => $paymentMethod->card->exp_year,
                        'exp_year_short' => $paymentMethod->card->exp_year_short,
                    ],
                    'billing_details' => [
                        'name' => $paymentMethod->billing_details->name,
                    ],
                    'alias' => $paymentMethod->alias,
                    'metadata' => (array) $paymentMethod->metadata,
                ];
            })
            ->values()
            ->all();

        if ($mockTokens === []) {
            return $userTokens;
        }

        return MockEfevooPaymentSupport::mergePaymentMethodsForCheckout($userTokens, $mockTokens);
    }

    public function syncDraft(
        SyncLaboratoryCheckoutDraftRequest $request,
        LaboratoryBrand $laboratoryBrand,
        SyncLaboratoryCheckoutDraftAction $action,
    ) {
        $validated = $request->validated();

        $draft = $action(
            $request->user()->customer,
            $laboratoryBrand,
            [
                'step' => $validated['step'],
                'contact_id' => isset($validated['contact_id']) ? (int) $validated['contact_id'] : null,
                'address_id' => isset($validated['address_id']) ? (int) $validated['address_id'] : null,
                'payment_method' => $validated['payment_method'] ?? null,
                'coupon_id' => isset($validated['coupon_id']) ? (int) $validated['coupon_id'] : null,
            ],
        );

        $query = array_filter([
            'step' => $draft->checkout_step,
            'contact' => $draft->contact_id
                ?? $validated['contact_id']
                ?? $request->query('contact'),
            'address' => $draft->address_id
                ?? $validated['address_id']
                ?? $request->query('address'),
            'payment_method' => $draft->payment_method
                ?? ($validated['payment_method'] ?? null)
                ?? $request->query('payment_method'),
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()->route('laboratory.checkout', [
            'laboratory_brand' => $laboratoryBrand,
            ...$query,
        ]);
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

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $laboratoryBrand,
            ],
            [
                'contact_id' => $contact->id,
                'address_id' => $request->filled('address') ? (int) $request->input('address') : null,
                'payment_method' => $request->input('payment_method'),
                'checkout_step' => 'appointment',
            ],
        );

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
            ?->timezone('America/Monterrey')
            ?->locale('es')
            ?->isoFormat('dddd D [de] MMMM [de] YYYY, h:mm a');
    }

    /**
     * @param  array<string, mixed>|null  $savedCheckout
     */
    private function ensurePendingLaboratoryAppointment(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        ?array $savedCheckout,
        Request $request,
    ): ?\App\Models\LaboratoryAppointment {
        $step = $request->query('step') ?? ($savedCheckout['checkout_step'] ?? null);

        $shouldEnsure = in_array($step, ['appointment', 'confirmation'], true)
            || in_array($savedCheckout['checkout_step'] ?? null, ['appointment', 'confirmation'], true);

        if (! $shouldEnsure) {
            return null;
        }

        $contactId = $savedCheckout['contact_id'] ?? $request->query('contact');
        if (! $contactId) {
            return null;
        }

        $contact = $customer->contacts()->find($contactId);
        if (! $contact) {
            return null;
        }

        return app(SyncLaboratoryAppointmentFromContactAction::class)(
            $customer,
            $laboratoryBrand,
            $contact,
        );
    }
}
