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
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartUserActivityResolver;
use App\Services\Laboratory\LaboratoryAppointmentCheckoutResolver;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;
use App\Services\Laboratory\LaboratoryCheckoutFlowEligibility;
use App\Services\Laboratory\LaboratoryCheckoutStepGuard;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Support\AppEnvironmentLabel;
use App\Support\ClientContext;
use App\Support\MockEfevooPaymentSupport;
use Illuminate\Http\Request;
use App\Services\Tracking\InitiateCheckout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class LaboratoryCheckoutController extends Controller
{
    public function __invoke(
        Request $request,
        LaboratoryBrand $laboratoryBrand,
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        CouponService $couponService,
        LaboratoryCheckoutFlowEligibility $laboratoryCheckoutFlowEligibility,
        LaboratoryCheckoutStepGuard $laboratoryCheckoutStepGuard,
        LaboratoryAppointmentCheckoutResolver $laboratoryAppointmentCheckoutResolver,
        CartAbandonmentService $cartAbandonmentService,
        SyncMonitoringCartService $syncMonitoringCartService,
        CartUserActivityResolver $cartUserActivityResolver,
    )
    {
        Log::info('Laboratory checkout: request started', [
            'user_id' => $request->user()?->id,
            'brand' => $laboratoryBrand->value,
        ]);

        $customer = $request->user()->customer;

        if (! $request->header('X-Inertia-Partial-Data')) {
            $activeCart = $syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);
            if ($activeCart !== null) {
                $cartAbandonmentService->maybeRecordResumed($activeCart, ClientContext::fromRequest($request));
                $cartUserActivityResolver->recordCheckoutVisit($activeCart, $laboratoryBrand->value);
            }
        }

        $laboratoryCartItems = $customer
            ->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        Log::info('Laboratory checkout: cart loaded', [
            'user_id' => $userId = $request->user()->id,
            'items' => $laboratoryCartItems->count(),
        ]);

        $totals = $calculateTotalsAndDiscountAction(
            $laboratoryCartItems
        );

        $customer = $request->user()->customer;
        try {
            $balancePresentation = $couponService->buildCheckoutCreditPresentation($userId, (int) $totals['total']);
        } catch (\Throwable $e) {
            Log::error('Checkout: buildCheckoutCreditPresentation failed', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            $balancePresentation = [
                'balanceCouponsCents' => 0,
                'formattedBalanceCoupons' => null,
                'availableBalanceCoupons' => [],
                'coupons' => [],
                'cartTotalCents' => (int) $totals['total'],
            ];
        }
        $availableCoupons = collect($balancePresentation['availableBalanceCoupons']);
        $mockTokens = MockEfevooPaymentSupport::isMockMode()
            ? MockEfevooPaymentSupport::ensureTestTokensForCustomer($customer)
            : [];
        $paymentMethods = $this->resolveCheckoutPaymentMethods($customer, $mockTokens);

        Log::info('Laboratory checkout: payment methods resolved', [
            'user_id' => $userId,
            'methods' => count($paymentMethods),
        ]);

        try {
            InitiateCheckout::track(
                contents: [
                    ...$laboratoryCartItems
                        ->filter(fn ($item) => $item->laboratoryTest !== null)
                        ->map(function ($item) {
                            return [
                                'id' => (string) $item->laboratoryTest->gda_id,
                                'quantity' => 1,
                            ];
                        })
                        ->all(),
                ],
                value: floatval(str_replace(',', '', formattedCents($totals['total']))),
                source: 'laboratory',
                customProperties: [
                    'brand' => $laboratoryBrand->value,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('InitiateCheckout tracking failed', [
                'message' => $e->getMessage(),
                'brand' => $laboratoryBrand->value,
            ]);
        }

        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand);
        $laboratoryAppointment = null;
        $pendingLaboratoryAppointment = null;
        $callbackPreferenceSavedAtFormatted = null;

        if ($requiresAppointment) {
            $laboratoryAppointment = $laboratoryAppointmentCheckoutResolver
                ->payableConfirmedAppointment($customer, $laboratoryBrand);

            if (! $laboratoryAppointment) {
                $pendingLaboratoryAppointment = $laboratoryAppointmentCheckoutResolver
                    ->pendingAppointment($customer, $laboratoryBrand);
                $callbackPreferenceSavedAtFormatted = $this->formatCallbackPreferenceSavedAt(
                    $pendingLaboratoryAppointment
                );
            }
        }

        Log::info('Laboratory checkout: appointment state resolved', [
            'user_id' => $userId,
            'requires_appointment' => $requiresAppointment,
            'has_confirmed_appointment' => $laboratoryAppointment !== null,
            'has_pending_appointment' => $pendingLaboratoryAppointment !== null,
        ]);

        $savedCheckout = null;
        if (Schema::hasTable('laboratory_checkout_drafts')) {
            $savedCheckout = LaboratoryCheckoutDraft::query()
                ->where('customer_id', $customer->id)
                ->where('laboratory_brand', $laboratoryBrand)
                ->first()
                ?->forCheckout();
        } else {
            Log::error('Checkout: laboratory_checkout_drafts table is missing');
        }

        if (is_array($savedCheckout) && ! empty($savedCheckout['coupon_id'])) {
            $availableCouponIds = $availableCoupons->pluck('id');
            if (! $availableCouponIds->contains((int) $savedCheckout['coupon_id'])) {
                $savedCheckout['coupon_id'] = null;
            }
        }

        $savedCheckoutForSteps = is_array($savedCheckout) ? $savedCheckout : [];
        if ($request->filled('contact')) {
            $savedCheckoutForSteps['contact_id'] = (string) $request->query('contact');
        }
        if ($request->filled('address')) {
            $savedCheckoutForSteps['address_id'] = (string) $request->query('address');
        }

        if ($requiresAppointment && ! $laboratoryAppointment && ! $pendingLaboratoryAppointment) {
            $pendingLaboratoryAppointment = $this->ensurePendingLaboratoryAppointment(
                $customer,
                $laboratoryBrand,
                $savedCheckout,
                $request,
                $laboratoryCheckoutFlowEligibility->usesAppointmentFirstFlow($customer, $laboratoryBrand),
            );

            if ($pendingLaboratoryAppointment) {
                $callbackPreferenceSavedAtFormatted = $this->formatCallbackPreferenceSavedAt(
                    $pendingLaboratoryAppointment
                );
            }
        }

        $usesAppointmentFirstFlow = $laboratoryCheckoutFlowEligibility->usesAppointmentFirstFlow(
            $customer,
            $laboratoryBrand,
        );

        $stepResolution = $laboratoryCheckoutStepGuard->resolveAccessibleStep(
            $customer,
            $laboratoryBrand,
            $request->query('step'),
            $savedCheckoutForSteps['checkout_step'] ?? (is_array($savedCheckout) ? ($savedCheckout['checkout_step'] ?? null) : null),
            $laboratoryAppointment !== null,
            $savedCheckoutForSteps !== [] ? $savedCheckoutForSteps : $savedCheckout,
        );

        if ($stepResolution->shouldRedirect($request->query('step'), is_array($savedCheckout) ? ($savedCheckout['checkout_step'] ?? null) : null)) {
            if ($stepResolution->updateDraft && Schema::hasTable('laboratory_checkout_drafts')) {
                LaboratoryCheckoutDraft::query()->updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'laboratory_brand' => $laboratoryBrand,
                    ],
                    [
                        'checkout_step' => $stepResolution->step,
                    ],
                );
            }

            if (is_array($savedCheckout)) {
                $savedCheckout['checkout_step'] = $stepResolution->step;
            }

            $redirectQuery = array_filter([
                'step' => $stepResolution->step,
                'contact' => $request->query('contact') ?? ($savedCheckout['contact_id'] ?? null),
                'address' => $request->query('address') ?? ($savedCheckout['address_id'] ?? null),
            ], fn ($value) => $value !== null && $value !== '');

            return redirect()
                ->route('laboratory.checkout', [
                    'laboratory_brand' => $laboratoryBrand,
                    ...$redirectQuery,
                ])
                ->with(
                    'checkout_step_notice',
                    $stepResolution->message
                        ?? $this->checkoutStepNoticeForBlockedPayment(
                            $request->query('step'),
                            $laboratoryCheckoutStepGuard,
                            $customer,
                            $laboratoryBrand,
                        ),
                );
        }

        Log::info('Laboratory checkout: building inertia response', ['user_id' => $userId]);

        try {
            return Inertia::render('LaboratoryCheckout', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            'savedCheckout' => $savedCheckout,
            'requiresAppointment' => $requiresAppointment,
            'usesAppointmentFirstFlow' => $usesAppointmentFirstFlow,
            'checkoutStepNotice' => session('checkout_step_notice'),
            'laboratoryAppointment' => $this->appointmentForCheckout($laboratoryAppointment),
            'pendingLaboratoryAppointment' => $this->appointmentForCheckout($pendingLaboratoryAppointment),
            'callbackPreferenceSavedAtFormatted' => $callbackPreferenceSavedAtFormatted,
            ...$totals,
            ...$balancePresentation,
            'balanceCreditPresentation' => $balancePresentation,
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
        } catch (\Throwable $e) {
            Log::error('Laboratory checkout: render failed', [
                'user_id' => $request->user()?->id,
                'brand' => $laboratoryBrand->value,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }
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

    public function syncDraft(
        SyncLaboratoryCheckoutDraftRequest $request,
        LaboratoryBrand $laboratoryBrand,
        SyncLaboratoryCheckoutDraftAction $action,
        LaboratoryCheckoutStepGuard $laboratoryCheckoutStepGuard,
    ) {
        $validated = $request->validated();
        $customer = $request->user()->customer;

        if (! $laboratoryCheckoutStepGuard->canSyncDraftStep($customer, $laboratoryBrand, $validated['step'])) {
            $query = array_filter([
                'step' => LaboratoryCheckoutStepGuard::STEP_APPOINTMENT,
                'contact' => $validated['contact_id'] ?? $request->query('contact'),
                'address' => $validated['address_id'] ?? $request->query('address'),
            ], fn ($value) => $value !== null && $value !== '');

            return redirect()
                ->route('laboratory.checkout', [
                    'laboratory_brand' => $laboratoryBrand,
                    ...$query,
                ])
                ->with(
                    'checkout_step_notice',
                    $laboratoryCheckoutStepGuard->paymentBlockedBeforeAppointmentMessage(),
                );
        }

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
            ClientContext::fromRequest($request),
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
        LaboratoryCheckoutStepGuard $laboratoryCheckoutStepGuard,
    ) {
        $customer = $request->user()->customer;

        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
            abort(422, 'El carrito no requiere cita.');
        }

        $contact = $customer->contacts()->findOrFail($request->validated('contact_id'));
        $action($customer, $laboratoryBrand, $contact);

        $appointmentFirst = $laboratoryCheckoutStepGuard->usesAppointmentFirstFlow($customer, $laboratoryBrand);

        $draftAttributes = [
            'contact_id' => $contact->id,
            'address_id' => $request->filled('address') ? (int) $request->input('address') : null,
            'checkout_step' => LaboratoryCheckoutStepGuard::STEP_APPOINTMENT,
        ];

        if (! $appointmentFirst) {
            $draftAttributes['payment_method'] = $request->input('payment_method');
        }

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $laboratoryBrand,
            ],
            $draftAttributes,
        );

        $query = array_filter([
            'step' => LaboratoryCheckoutStepGuard::STEP_APPOINTMENT,
            'contact' => $request->validated('contact_id'),
            'address' => $request->input('address'),
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()->route('laboratory.checkout', [
            'laboratory_brand' => $laboratoryBrand,
            ...$query,
        ]);
    }

    private function appointmentForCheckout(?\App\Models\LaboratoryAppointment $appointment): ?array
    {
        if (! $appointment) {
            return null;
        }

        $appointment->loadMissing('laboratoryStore');

        return [
            'id' => $appointment->id,
            'brand' => $appointment->brand?->value ?? $appointment->brand,
            'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            'patient_full_name' => $appointment->patient_full_name,
            'formatted_patient_gender' => $appointment->formatted_patient_gender,
            'formatted_patient_birth_date' => $appointment->formatted_patient_birth_date,
            'patient_phone' => $appointment->patient_phone,
            'formatted_appointment_date' => $appointment->formatted_appointment_date,
            'callback_availability_starts_at' => $appointment->callback_availability_starts_at?->toIso8601String(),
            'callback_availability_ends_at' => $appointment->callback_availability_ends_at?->toIso8601String(),
            'patient_callback_comment' => $appointment->patient_callback_comment,
            'has_left_callback_info' => $appointment->has_left_callback_info,
            'formatted_request_saved_at' => $appointment->formatted_request_saved_at,
            'formatted_callback_availability_range' => $appointment->formatted_callback_availability_range,
            'laboratory_store' => $appointment->laboratoryStore ? [
                'name' => $appointment->laboratoryStore->name,
                'address' => $appointment->laboratoryStore->address,
            ] : null,
            'is_payable' => app(LaboratoryAppointmentPaymentValidity::class)
                ->isValidForPayment($appointment),
        ];
    }

    private function checkoutStepNoticeForBlockedPayment(
        ?string $requestedStep,
        LaboratoryCheckoutStepGuard $laboratoryCheckoutStepGuard,
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
    ): ?string {
        if (! in_array($requestedStep, ['payment', 'confirmation'], true)) {
            return null;
        }

        return $laboratoryCheckoutStepGuard->resolvePaymentBlockMessage($customer, $laboratoryBrand);
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
        bool $usesAppointmentFirstFlow = false,
        ?LaboratoryAppointmentCheckoutResolver $laboratoryAppointmentCheckoutResolver = null,
    ): ?\App\Models\LaboratoryAppointment {
        $laboratoryAppointmentCheckoutResolver ??= app(LaboratoryAppointmentCheckoutResolver::class);
        $step = $request->query('step') ?? ($savedCheckout['checkout_step'] ?? null);

        $shouldEnsure = $usesAppointmentFirstFlow
            ? in_array($step, ['appointment', 'payment', 'confirmation'], true)
                || in_array($savedCheckout['checkout_step'] ?? null, ['appointment', 'payment', 'confirmation'], true)
            : in_array($step, ['appointment', 'confirmation'], true)
                || in_array($savedCheckout['checkout_step'] ?? null, ['appointment', 'confirmation'], true);

        if (! $shouldEnsure) {
            return null;
        }

        if ($laboratoryAppointmentCheckoutResolver->payableConfirmedAppointment($customer, $laboratoryBrand)) {
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
