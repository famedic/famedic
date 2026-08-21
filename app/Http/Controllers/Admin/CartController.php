<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Services\Carts\CartOperationalInsightResolver;
use App\Services\Carts\CartPaymentAttemptCorrelator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('view carts') || abort(403);

        $filters = collect($request->only([
            'search',
            'type',
            'display_status',
            'operational_filter',
            'operational_bucket',
            'payment_status',
            'checkout_stage',
            'appointment_filter',
            'contact_filter',
            'customer_segment',
            'brand',
            'amount_range',
            'inactivity_range',
            'start_date',
            'end_date',
        ]))->filter(fn ($v) => $v !== null && $v !== '')->all();

        $today = now('America/Monterrey');
        $usingDefaultPeriod = empty($filters['start_date']) && empty($filters['end_date']);
        if ($usingDefaultPeriod) {
            $filters['start_date'] = $today->copy()->subDays(6)->toDateString();
            $filters['end_date'] = $today->toDateString();
        }

        $start = ! empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
        $end = ! empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;

        $query = Cart::query()
            ->addSelect('carts.*')
            ->addSelect([
                'previous_laboratory_purchases_count' => DB::table('laboratory_purchases')
                    ->join('customers as purchase_customers', 'purchase_customers.id', '=', 'laboratory_purchases.customer_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_customers.user_id', 'carts.user_id')
                    ->whereNull('laboratory_purchases.deleted_at')
                    ->whereColumn('laboratory_purchases.created_at', '<', 'carts.created_at'),
                'previous_online_pharmacy_purchases_count' => DB::table('online_pharmacy_purchases')
                    ->join('customers as pharmacy_purchase_customers', 'pharmacy_purchase_customers.id', '=', 'online_pharmacy_purchases.customer_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('pharmacy_purchase_customers.user_id', 'carts.user_id')
                    ->whereNull('online_pharmacy_purchases.deleted_at')
                    ->whereColumn('online_pharmacy_purchases.created_at', '<', 'carts.created_at'),
            ])
            ->with([
                'items',
                'laboratoryAppointments.laboratoryStore',
                'laboratoryPurchases.transactions',
                'user.customer.laboratoryCartItems.laboratoryTest',
                'user.customer.laboratoryAppointments',
                'user.customer.laboratoryCheckoutDrafts.contact',
                'user.customer.laboratoryCheckoutDrafts.address',
                'user.customer.laboratoryPurchases.transactions',
            ])
            ->withCount('items')
            ->adminMonitoringFilter($filters, $start, $end)
            ->orderByDesc('updated_at');

        $carts = $query->paginate(25)->withQueryString();
        $paymentInsights = app(CartPaymentAttemptCorrelator::class)->forCarts($carts->getCollection());

        $trayFilters = collect($filters)
            ->except([
                'display_status',
                'operational_filter',
                'operational_bucket',
                'payment_status',
                'checkout_stage',
                'appointment_filter',
                'contact_filter',
            ])
            ->all();
        $statusMetricFilters = collect($filters)->except(['display_status'])->all();
        $metricsBase = Cart::query()
            ->adminMonitoringFilter($statusMetricFilters, $start, $end);
        $trayMetricsBase = Cart::query()
            ->adminMonitoringFilter($trayFilters, $start, $end);

        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $metrics = [
            'total' => (clone $trayMetricsBase)->count(),
            'active' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '>=', $staleBefore)
                ->count(),
            'abandoned' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '<', $staleBefore)
                ->count(),
            'completed' => (clone $metricsBase)->where('status', MonitoringCartStatus::Completed)->count(),
            'appointment_pending_confirmation' => (clone $metricsBase)
                ->appointmentPendingConfirmation()
                ->count(),
            'appointment_confirmed_pending_payment' => (clone $metricsBase)
                ->appointmentConfirmedPendingPayment()
                ->count(),
            'no_progress' => (clone $trayMetricsBase)
                ->checkoutStageFilter('no_progress')
                ->count(),
        ];

        $den = $metrics['completed'] + $metrics['abandoned'];
        $metrics['conversion_percent'] = $den > 0
            ? round(100 * $metrics['completed'] / $den, 1)
            : null;
        $metrics['attention_required'] = (clone $trayMetricsBase)->operationalBucket('attention')->count();
        $metrics['payment_attention'] = (clone $trayMetricsBase)->operationalBucket('payment')->count();
        $metrics['appointment_attention'] = (clone $trayMetricsBase)->operationalBucket('appointment')->count();
        $metrics['contact_attention'] = (clone $trayMetricsBase)->operationalBucket('contact')->count();

        $operationalResolver = app(CartOperationalInsightResolver::class);
        $carts->getCollection()->transform(
            fn (Cart $cart) => $this->serializeCartRow($cart, $paymentInsights[(int) $cart->id] ?? null, $operationalResolver),
        );

        return Inertia::render('Admin/Carts', [
            'carts' => $carts,
            'filters' => $filters,
            'metrics' => $metrics,
            'usingDefaultPeriod' => $usingDefaultPeriod,
            'abandonedThresholdMinutes' => Cart::ABANDONED_AFTER_MINUTES,
            'canViewCartDetails' => $request->user()->administrator->hasPermissionTo('view cart details'),
            'canExport' => $request->user()->administrator->hasPermissionTo('view carts'),
        ]);
    }

    public function show(Request $request, Cart $cart)
    {
        $request->user()->administrator->hasPermissionTo('view cart details') || abort(403);

        $this->attachPreviousPurchaseCounts($cart);

        $cart->load([
            'items',
            'laboratoryAppointments.laboratoryStore',
            'laboratoryPurchases.transactions',
            'user.customer.laboratoryAppointments.laboratoryStore',
            'user.customer.laboratoryCheckoutDrafts.contact',
            'user.customer.laboratoryCheckoutDrafts.address',
            'user.customer.laboratoryPurchases.transactions',
            'user.customer.onlinePharmacyPurchases',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->serializeCart360Detail($cart, $request),
            ]);
        }

        return Inertia::render('Admin/CartShow', [
            'cart' => $this->serializeCartDetail($cart),
        ]);
    }

    private function attachPreviousPurchaseCounts(Cart $cart): void
    {
        $cart->setAttribute('previous_laboratory_purchases_count', DB::table('laboratory_purchases')
            ->join('customers as purchase_customers', 'purchase_customers.id', '=', 'laboratory_purchases.customer_id')
            ->where('purchase_customers.user_id', $cart->user_id)
            ->whereNull('laboratory_purchases.deleted_at')
            ->where('laboratory_purchases.created_at', '<', $cart->created_at)
            ->count());

        $cart->setAttribute('previous_online_pharmacy_purchases_count', DB::table('online_pharmacy_purchases')
            ->join('customers as pharmacy_purchase_customers', 'pharmacy_purchase_customers.id', '=', 'online_pharmacy_purchases.customer_id')
            ->where('pharmacy_purchase_customers.user_id', $cart->user_id)
            ->whereNull('online_pharmacy_purchases.deleted_at')
            ->where('online_pharmacy_purchases.created_at', '<', $cart->created_at)
            ->count());
    }

    private function serializeCartRow(
        Cart $cart,
        ?array $paymentInsight = null,
        ?CartOperationalInsightResolver $operationalResolver = null,
    ): array
    {
        $display = $cart->displayStatus();
        $operationalResolver ??= app(CartOperationalInsightResolver::class);
        $operationalInsight = $operationalResolver->resolve($cart, $paymentInsight);

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
            ] : null,
            'customer_history' => $this->serializeCustomerHistory($cart),
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'lab_brands' => $cart->labBrands(),
            'cart_summary' => $this->serializeCartSummary($cart),
            'appointment_pending_confirmation' => $cart->hasAppointmentPendingConfirmation(),
            'appointment_confirmed_pending_payment' => $cart->hasAppointmentConfirmedPendingPayment(),
            'items_count' => $cart->items_count ?? $cart->items->count(),
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $display,
            'display_status_label' => $cart->displayStatusLabel(),
            'updated_at' => $cart->updated_at?->toIso8601String(),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
            'inactive_for_minutes' => $cart->inactiveForMinutes(),
            'inactive_for_label' => $cart->inactiveForLabel(),
            'abandoned_at_human' => $cart->abandonedAt()?->timezone('America/Monterrey')->format('d/m/Y H:i'),
            'abandoned_threshold_minutes' => Cart::ABANDONED_AFTER_MINUTES,
            'checkout_summary' => $cart->type === MonitoringCartType::Lab
                ? $this->serializeCheckoutSummaryForRow($cart)
                : null,
            'payment_insight' => $paymentInsight,
            'operational_insight' => $operationalInsight,
            'current_stage' => $this->serializeCurrentStageForRow($cart, $paymentInsight),
            'operational_signals' => $this->serializeOperationalSignalsForRow($cart, $paymentInsight),
        ];
    }

    /**
     * @return array{previous_purchases_count: int, segment: string, label: string}
     */
    private function serializeCustomerHistory(Cart $cart): array
    {
        $previousPurchases = (int) ($cart->previous_laboratory_purchases_count ?? 0)
            + (int) ($cart->previous_online_pharmacy_purchases_count ?? 0);

        $segment = match (true) {
            $previousPurchases >= 2 => 'recurrent',
            $previousPurchases === 1 => 'existing',
            default => 'new',
        };

        $segmentLabel = match ($segment) {
            'recurrent' => 'Cliente recurrente',
            'existing' => 'Cliente existente',
            default => 'Cliente nuevo',
        };

        return [
            'previous_purchases_count' => $previousPurchases,
            'segment' => $segment,
            'label' => $previousPurchases > 0
                ? $segmentLabel.' · '.$previousPurchases.' '.($previousPurchases === 1 ? 'compra' : 'compras')
                : $segmentLabel,
        ];
    }

    /**
     * @return array{brand_label: string|null, items_label: string, total_label: string}
     */
    private function serializeCartSummary(Cart $cart): array
    {
        $itemsCount = (int) ($cart->items_count ?? $cart->items->count());
        $itemNoun = $cart->type === MonitoringCartType::Lab
            ? ($itemsCount === 1 ? 'estudio' : 'estudios')
            : ($itemsCount === 1 ? 'producto' : 'productos');

        return [
            'brand_label' => collect($cart->labBrands())->pluck('label')->filter()->implode(', ') ?: null,
            'items_label' => $itemsCount.' '.$itemNoun,
            'total_label' => formattedPrice((float) $cart->total),
        ];
    }

    /**
     * @return array{key: string, label: string, detail: string|null, tone: string}
     */
    private function serializeCurrentStageForRow(Cart $cart, ?array $paymentInsight = null): array
    {
        if ($cart->displayStatus() === 'completed') {
            return [
                'key' => 'completed',
                'label' => 'Compra completada',
                'detail' => 'Monitoreo completado',
                'tone' => 'green',
            ];
        }

        if (($paymentInsight['should_display'] ?? false) === true) {
            $status = $paymentInsight['status'] ?? null;
            if ($status === PaymentAttempt::STATUS_DECLINED || $status === PaymentAttempt::STATUS_ERROR) {
                return [
                    'key' => 'payment_'.$status,
                    'label' => $status === PaymentAttempt::STATUS_DECLINED ? 'Pago rechazado' : 'Error al pagar',
                    'detail' => $this->paymentAttemptCountLabel((int) ($paymentInsight['attempts_count'] ?? 0)),
                    'tone' => 'red',
                ];
            }

            if ($status === PaymentAttempt::STATUS_PENDING || $status === PaymentAttempt::STATUS_PROCESSING) {
                return [
                    'key' => 'payment_pending',
                    'label' => 'Intento de pago pendiente',
                    'detail' => $this->paymentAttemptCountLabel((int) ($paymentInsight['attempts_count'] ?? 0)),
                    'tone' => 'amber',
                ];
            }
        }

        if ($cart->type !== MonitoringCartType::Lab) {
            return [
                'key' => 'cart',
                'label' => 'Carrito activo',
                'detail' => 'Farmacia',
                'tone' => 'slate',
            ];
        }

        $appointments = $cart->laboratoryAppointmentsForDisplay();
        $appointment = $appointments->first();
        if ($appointment) {
            if ($appointment->confirmed_at !== null && $appointment->laboratory_purchase_id === null) {
                return [
                    'key' => 'appointment_confirmed_pending_payment',
                    'label' => 'Cita confirmada sin pago',
                    'detail' => 'Pendiente de pago',
                    'tone' => 'violet',
                ];
            }

            if ($appointment->confirmed_at === null) {
                return [
                    'key' => 'appointment_pending',
                    'label' => 'Cita pendiente',
                    'detail' => 'Esperando confirmación',
                    'tone' => 'amber',
                ];
            }

            return [
                'key' => 'appointment_confirmed',
                'label' => 'Cita confirmada',
                'detail' => 'Con cita',
                'tone' => 'green',
            ];
        }

        $entries = $this->serializeCheckoutSummaryForRow($cart);
        $step = collect($entries)->pluck('checkout_step')->filter()->first();

        if (! is_string($step) || $step === '') {
            return [
                'key' => 'incomplete',
                'label' => 'Checkout incompleto',
                'detail' => null,
                'tone' => 'zinc',
            ];
        }

        $stepNumber = $this->checkoutStepNumber($step);
        $stepLabel = $this->checkoutStepLabel($step);
        $prefix = $cart->displayStatus() === 'abandoned' ? 'Abandonó en ' : 'En ';

        return [
            'key' => $step,
            'label' => $prefix.mb_strtolower($stepLabel),
            'detail' => $stepNumber ? 'Paso '.$stepNumber.' de 5' : null,
            'tone' => $step === 'payment' ? 'sky' : 'slate',
        ];
    }

    /**
     * @return list<array{key: string, label: string, tone: string, detail?: string|null}>
     */
    private function serializeOperationalSignalsForRow(Cart $cart, ?array $paymentInsight = null): array
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return [];
        }

        $signals = [];
        $paymentSignal = $this->paymentSignal($paymentInsight);

        if ($paymentSignal !== null) {
            $signals[] = $paymentSignal;
        }

        $appointment = $cart->laboratoryAppointmentsForDisplay()->first();

        if ($appointment) {
            if ($appointment->confirmed_at !== null && $appointment->laboratory_purchase_id === null) {
                $signals[] = ['key' => 'appointment_confirmed_pending_payment', 'label' => 'Cita confirmada sin pago', 'tone' => 'violet'];
            } elseif ($appointment->confirmed_at === null) {
                $signals[] = [
                    'key' => 'appointment_pending',
                    'label' => 'Cita pendiente',
                    'tone' => 'amber',
                    'detail' => $this->appointmentPendingForLabel($appointment),
                ];
            } else {
                $signals[] = ['key' => 'appointment_confirmed', 'label' => 'Cita confirmada', 'tone' => 'green'];
            }

            if ($appointment->has_left_callback_info) {
                $signals[] = ['key' => 'callback_requested', 'label' => 'Solicitó llamada', 'tone' => 'violet'];
            }

            if ($appointment->phone_call_intent_at !== null) {
                $signals[] = ['key' => 'phone_call_intent', 'label' => 'Intentó llamar', 'tone' => 'sky'];
            }
        } elseif ($cart->hasAppointmentPendingConfirmation() || $cart->hasAppointmentConfirmedPendingPayment()) {
            // Los scopes de métricas detectan cita por marca aunque no podamos asociar una sola cita al snapshot.
            $signals[] = [
                'key' => 'appointment_signal',
                'label' => $cart->hasAppointmentConfirmedPendingPayment() ? 'Cita confirmada sin pago' : 'Cita pendiente',
                'tone' => $cart->hasAppointmentConfirmedPendingPayment() ? 'violet' : 'amber',
            ];
        }

        return collect($signals)->take(3)->values()->all();
    }

    /**
     * @return array{key: string, label: string, tone: string, detail?: string|null}|null
     */
    private function paymentSignal(?array $paymentInsight): ?array
    {
        if (($paymentInsight['should_display'] ?? false) !== true) {
            return null;
        }

        $status = $paymentInsight['status'] ?? null;
        if (! in_array($status, [
            PaymentAttempt::STATUS_ERROR,
            PaymentAttempt::STATUS_DECLINED,
            PaymentAttempt::STATUS_PENDING,
            PaymentAttempt::STATUS_PROCESSING,
        ], true)) {
            return null;
        }

        $lastAttempt = $paymentInsight['last_attempt'] ?? [];
        $processorCode = $lastAttempt['processor_code'] ?? null;
        $processorMessage = $lastAttempt['processor_message'] ?? null;
        $elapsed = $lastAttempt['occurred_for_label'] ?? null;

        $detailParts = [];
        if ($status === PaymentAttempt::STATUS_DECLINED && filled($processorCode)) {
            $detailParts[] = 'Código '.$processorCode;
        } elseif ($status === PaymentAttempt::STATUS_ERROR && filled($processorMessage)) {
            $detailParts[] = $processorMessage;
        }
        if (filled($elapsed)) {
            $detailParts[] = $elapsed;
        }

        return [
            'key' => 'payment_'.$status,
            'label' => match ($status) {
                PaymentAttempt::STATUS_ERROR => 'Error técnico',
                PaymentAttempt::STATUS_DECLINED => 'Pago rechazado',
                default => 'Intento pendiente',
            },
            'tone' => $status === PaymentAttempt::STATUS_PENDING || $status === PaymentAttempt::STATUS_PROCESSING ? 'amber' : 'red',
            'detail' => $detailParts !== [] ? implode(' · ', $detailParts) : null,
        ];
    }

    private function paymentAttemptCountLabel(int $count): ?string
    {
        if ($count <= 0) {
            return null;
        }

        return $count.' '.($count === 1 ? 'intento' : 'intentos');
    }

    private function appointmentPendingForLabel(LaboratoryAppointment $appointment): ?string
    {
        if ($appointment->confirmed_at !== null || ! $appointment->created_at) {
            return null;
        }

        $minutes = max(0, $appointment->created_at->diffInMinutes(now()));
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours.' h';
        }

        return intdiv($hours, 24).' d';
    }

    private function checkoutStepNumber(string $step): ?int
    {
        return match ($step) {
            'patient' => 1,
            'address' => 2,
            'appointment' => 3,
            'payment' => 4,
            'confirmation' => 5,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCart360Detail(Cart $cart, Request $request): array
    {
        $paymentInsight = app(CartPaymentAttemptCorrelator::class)
            ->forCarts(collect([$cart]))[(int) $cart->id] ?? null;
        $purchase = $cart->type === MonitoringCartType::Lab ? $cart->relatedLaboratoryPurchase() : null;
        $appointment = $cart->type === MonitoringCartType::Lab
            ? $cart->laboratoryAppointmentsForDisplay()->first()
            : null;
        $checkoutEntries = $cart->type === MonitoringCartType::Lab
            ? $this->serializeCheckoutSummaryForRow($cart)
            : [];

        return [
            'cart' => $this->serialize360Cart($cart, $purchase),
            'customer' => $this->serialize360Customer($cart),
            'operational_insight' => app(CartOperationalInsightResolver::class)->resolve($cart, $paymentInsight),
            'checkout' => [
                'stage' => $this->serializeCurrentStageForRow($cart, $paymentInsight),
                'signals' => $this->serializeOperationalSignalsForRow($cart, $paymentInsight),
                'journey' => $this->serialize360Journey($cart, $checkoutEntries, $appointment, $purchase, $paymentInsight),
                'entries' => $checkoutEntries,
            ],
            'payment' => $this->serialize360Payment($paymentInsight),
            'appointment' => $appointment ? $this->serialize360Appointment($appointment) : null,
            'contact' => $appointment ? $this->serialize360Contact($appointment) : null,
            'history' => $this->serialize360History($cart),
            'links' => $this->serialize360Links($cart, $request, $purchase, $appointment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize360Cart(Cart $cart, ?LaboratoryPurchase $purchase): array
    {
        return [
            'id' => $cart->id,
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'brand_label' => collect($cart->labBrands())->pluck('label')->filter()->implode(', ') ?: null,
            'items_count' => $cart->items->count(),
            'items_label' => $this->serializeCartSummary($cart)['items_label'],
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $cart->displayStatus(),
            'display_status_label' => $cart->displayStatusLabel(),
            'status_summary' => $cart->displayStatus() === 'abandoned' && $cart->inactiveForLabel()
                ? 'Abandonado hace '.$cart->inactiveForLabel()
                : $cart->displayStatusLabel(),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
            'created_at_human' => $cart->created_at?->format('d/m/Y H:i'),
            'completed_at_human' => $cart->completed_at?->format('d/m/Y H:i'),
            'related_purchase' => $purchase ? [
                'label' => 'Compra #'.$purchase->id,
                'created_at_human' => $purchase->created_at?->format('d/m/Y H:i'),
                'total_formatted' => $purchase->formatted_total ?? formattedPrice((float) $purchase->total),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize360Customer(Cart $cart): array
    {
        $history = $this->serializeCustomerHistory($cart);

        return [
            'name' => $cart->user?->full_name,
            'email' => $cart->user?->email,
            'phone' => $cart->user?->full_phone,
            'registered_at_human' => $cart->user?->created_at?->format('d/m/Y H:i'),
            'segment' => $history['segment'],
            'segment_label' => match ($history['segment']) {
                'recurrent' => 'Cliente recurrente',
                'existing' => 'Cliente existente',
                default => 'Cliente nuevo',
            },
            'previous_purchases_count' => $history['previous_purchases_count'],
            'previous_purchases_label' => $history['previous_purchases_count'].' '.($history['previous_purchases_count'] === 1 ? 'compra anterior' : 'compras anteriores'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checkoutEntries
     * @return list<array<string, mixed>>
     */
    private function serialize360Journey(
        Cart $cart,
        array $checkoutEntries,
        ?LaboratoryAppointment $appointment,
        ?LaboratoryPurchase $purchase,
        ?array $paymentInsight,
    ): array {
        $hasItems = $cart->items->isNotEmpty();
        $hasPatient = collect($checkoutEntries)->contains(fn (array $entry) => filled($entry['patient_name'] ?? null))
            || $purchase !== null;
        $hasAddress = collect($checkoutEntries)->contains(fn (array $entry) => filled($entry['address_short'] ?? null))
            || $purchase !== null;
        $requiresAppointment = $this->cartRequiresAppointment($cart);
        $paymentStatus = $this->paymentJourneyStatus($paymentInsight);
        $isCompleted = $cart->status === MonitoringCartStatus::Completed && $purchase !== null;

        return [
            [
                'key' => 'items',
                'label' => 'Estudios',
                'state' => $hasItems ? 'completed' : 'current',
                'detail' => $hasItems ? $this->serializeCartSummary($cart)['items_label'] : 'Sin items',
            ],
            [
                'key' => 'patient',
                'label' => 'Paciente',
                'state' => $hasPatient ? 'completed' : 'pending',
                'detail' => $hasPatient ? 'Registrado' : 'No registrado',
            ],
            [
                'key' => 'address',
                'label' => 'Dirección',
                'state' => $hasAddress ? 'completed' : 'pending',
                'detail' => $hasAddress ? 'Registrada' : 'No registrada',
            ],
            [
                'key' => 'appointment',
                'label' => 'Cita',
                'state' => $this->appointmentJourneyState($requiresAppointment, $appointment),
                'detail' => $this->appointmentJourneyDetail($requiresAppointment, $appointment),
            ],
            [
                'key' => 'payment',
                'label' => 'Pago',
                'state' => $paymentStatus['state'],
                'detail' => $paymentStatus['detail'],
            ],
            [
                'key' => 'purchase',
                'label' => 'Compra',
                'state' => $isCompleted ? 'completed' : 'pending',
                'detail' => $isCompleted ? 'Compra registrada' : 'Sin compra',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize360Payment(?array $paymentInsight): ?array
    {
        if ($paymentInsight === null) {
            return null;
        }

        if (($paymentInsight['confidence'] ?? null) === 'ambiguous') {
            return [
                'confidence' => 'ambiguous',
                'status' => 'ambiguous',
                'status_label' => 'Información de pago no determinada',
                'note' => 'Se encontraron intentos que no pueden asociarse de forma confiable a este carrito.',
            ];
        }

        if (($paymentInsight['should_display'] ?? false) !== true) {
            return null;
        }

        return [
            'confidence' => $paymentInsight['confidence'] ?? 'high',
            'gateway_label' => 'Efevoo',
            'status' => $paymentInsight['status'] ?? null,
            'status_label' => $paymentInsight['status_label'] ?? null,
            'status_tone' => $paymentInsight['status_tone'] ?? 'zinc',
            'attempts_count' => (int) ($paymentInsight['attempts_count'] ?? 0),
            'last_attempt' => $paymentInsight['last_attempt'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize360Appointment(LaboratoryAppointment $appointment): array
    {
        $status = $appointment->confirmed_at === null
            ? 'Pendiente'
            : ($appointment->laboratory_purchase_id === null ? 'Confirmada sin pago' : 'Confirmada');

        return [
            'brand_label' => $appointment->brand->label(),
            'store_name' => $appointment->laboratoryStore?->name,
            'store_address' => $appointment->laboratoryStore?->address,
            'appointment_date_human' => $appointment->formatted_appointment_date,
            'status_label' => $status,
            'waiting_label' => $appointment->confirmed_at === null ? 'Esperando confirmación '.$this->appointmentPendingForLabel($appointment) : null,
            'confirmed_at_human' => $appointment->confirmed_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize360Contact(LaboratoryAppointment $appointment): ?array
    {
        $hasPhoneIntent = $appointment->phone_call_intent_at !== null;
        $hasCallback = (bool) $appointment->has_left_callback_info;

        if (! $hasPhoneIntent && ! $hasCallback) {
            return null;
        }

        return [
            'phone_call_intent' => $hasPhoneIntent ? [
                'label' => 'Intentó llamar',
                'at_human' => $appointment->formatted_phone_call_intent_at,
            ] : null,
            'callback_requested' => $hasCallback ? [
                'label' => 'Solicitó llamada',
                'availability_label' => $appointment->formatted_callback_availability_range,
                'comment' => $this->shortText($appointment->patient_callback_comment, 120),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize360History(Cart $cart): array
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return [
                'registered_at_human' => $cart->user?->created_at?->format('d/m/Y H:i'),
                'previous_purchases_count' => 0,
                'last_purchase_label' => 'Sin compras anteriores',
                'historical_value_formatted' => formattedPrice(0),
            ];
        }

        $labPurchases = $customer->relationLoaded('laboratoryPurchases')
            ? $customer->laboratoryPurchases
            : $customer->laboratoryPurchases()->get();
        $pharmacyPurchases = $customer->relationLoaded('onlinePharmacyPurchases')
            ? $customer->onlinePharmacyPurchases
            : $customer->onlinePharmacyPurchases()->get();

        $previousLab = $labPurchases->filter(fn (LaboratoryPurchase $purchase) => $purchase->created_at && $purchase->created_at->lt($cart->created_at));
        $previousPharmacy = $pharmacyPurchases->filter(fn (OnlinePharmacyPurchase $purchase) => $purchase->created_at && $purchase->created_at->lt($cart->created_at));
        $previousPurchases = $previousLab
            ->map(fn (LaboratoryPurchase $purchase) => [
                'created_at' => $purchase->created_at,
                'amount' => (float) $purchase->total,
            ])
            ->concat($previousPharmacy->map(fn (OnlinePharmacyPurchase $purchase) => [
                'created_at' => $purchase->created_at,
                'amount' => ((float) ($purchase->total_cents ?? 0)) / 100,
            ]))
            ->sortByDesc('created_at')
            ->values();

        $lastPurchase = $previousPurchases->first();

        return [
            'registered_at_human' => $cart->user?->created_at?->format('d/m/Y H:i'),
            'previous_purchases_count' => $previousPurchases->count(),
            'last_purchase_label' => $lastPurchase
                ? 'Última compra: '.$lastPurchase['created_at']->format('d/m/Y')
                : 'Sin compras anteriores',
            'historical_value_formatted' => formattedPrice((float) $previousPurchases->sum('amount')),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function serialize360Links(
        Cart $cart,
        Request $request,
        ?LaboratoryPurchase $purchase,
        ?LaboratoryAppointment $appointment,
    ): array {
        $administrator = $request->user()->administrator;

        return [
            'cart_url' => route('admin.carts.show', $cart),
            'purchase_url' => $purchase && $this->adminCanIfPermissionExists($administrator, 'laboratory-purchases.manage')
                ? route('admin.laboratory-purchases.show', $purchase)
                : null,
            'appointment_url' => $appointment && $administrator->laboratoryConcierge
                ? route('admin.laboratory-appointments.show', $appointment)
                : null,
        ];
    }

    private function adminCanIfPermissionExists(mixed $administrator, string $permission): bool
    {
        if (! \Spatie\Permission\Models\Permission::query()
            ->where('name', $permission)
            ->where('guard_name', 'web')
            ->exists()) {
            return false;
        }

        return $administrator->hasPermissionTo($permission);
    }

    private function cartRequiresAppointment(Cart $cart): bool
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return false;
        }

        $testIds = $cart->items->pluck('product_id')->filter()->unique()->values();
        if ($testIds->isEmpty()) {
            return $cart->hasAppointmentPendingConfirmation() || $cart->hasAppointmentConfirmedPendingPayment();
        }

        return LaboratoryTest::query()
            ->whereIn('id', $testIds)
            ->where('requires_appointment', true)
            ->exists();
    }

    private function appointmentJourneyState(bool $requiresAppointment, ?LaboratoryAppointment $appointment): string
    {
        if (! $requiresAppointment) {
            return 'pending';
        }

        if (! $appointment) {
            return 'pending';
        }

        return $appointment->confirmed_at === null ? 'current' : 'completed';
    }

    private function appointmentJourneyDetail(bool $requiresAppointment, ?LaboratoryAppointment $appointment): string
    {
        if (! $requiresAppointment) {
            return 'No aplica';
        }

        if (! $appointment) {
            return 'No seleccionada';
        }

        if ($appointment->confirmed_at === null) {
            return 'Pendiente';
        }

        return $appointment->laboratory_purchase_id === null ? 'Confirmada sin pago' : 'Confirmada';
    }

    /**
     * @return array{state: string, detail: string}
     */
    private function paymentJourneyStatus(?array $paymentInsight): array
    {
        if ($paymentInsight === null) {
            return ['state' => 'pending', 'detail' => 'No iniciado'];
        }

        if (($paymentInsight['confidence'] ?? null) === 'ambiguous') {
            return ['state' => 'pending', 'detail' => 'Información no determinada'];
        }

        return match ($paymentInsight['status'] ?? null) {
            PaymentAttempt::STATUS_APPROVED => ['state' => 'completed', 'detail' => 'Aprobado'],
            PaymentAttempt::STATUS_DECLINED => ['state' => 'failed', 'detail' => 'Rechazado'],
            PaymentAttempt::STATUS_ERROR => ['state' => 'failed', 'detail' => 'Error técnico'],
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => ['state' => 'current', 'detail' => 'Pendiente'],
            default => ['state' => 'pending', 'detail' => 'No iniciado'],
        };
    }

    private function serializeCartDetail(Cart $cart): array
    {
        $isLab = $cart->type === MonitoringCartType::Lab;
        $testsById = collect();

        if ($isLab) {
            $testIds = $cart->items->pluck('product_id')->filter()->unique()->values();
            if ($testIds->isNotEmpty()) {
                $testsById = LaboratoryTest::query()
                    ->whereIn('id', $testIds)
                    ->get()
                    ->keyBy(fn (LaboratoryTest $test) => (string) $test->id);
            }
        }

        $items = $cart->items->map(function ($row) use ($isLab, $testsById) {
            $test = $testsById->get((string) $row->product_id);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'quantity' => $row->quantity,
                'unit_price' => (string) $row->price,
                'unit_price_formatted' => formattedPrice((float) $row->price),
                'line_total' => (string) round((float) $row->price * (int) $row->quantity, 2),
                'line_total_formatted' => formattedPrice(round((float) $row->price * (int) $row->quantity, 2)),
                'brand_label' => $test?->brand?->label(),
                'requires_appointment' => (bool) ($test?->requires_appointment ?? false),
            ];
        });

        $purchase = $isLab ? $cart->relatedLaboratoryPurchase() : null;
        $checkoutDrafts = $isLab ? $this->serializeCheckoutDrafts($cart) : [];
        $appointments = $isLab
            ? $cart->laboratoryAppointmentsForDisplay()->map(
                fn (LaboratoryAppointment $appointment) => $this->serializeLaboratoryAppointmentForAdmin($appointment),
            )->values()->all()
            : [];

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
                'phone' => $cart->user->full_phone,
                'admin_url' => route('admin.users.show', $cart->user),
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $isLab ? 'Laboratorio' : 'Farmacia',
            'lab_brands' => $cart->labBrands(),
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $cart->displayStatus(),
            'monitoring_status' => $cart->status->value,
            'monitoring_status_label' => $cart->status === MonitoringCartStatus::Completed ? 'Completado en monitoreo' : 'Activo en monitoreo',
            'appointment_pending_confirmation' => $cart->hasAppointmentPendingConfirmation(),
            'appointment_confirmed_pending_payment' => $cart->hasAppointmentConfirmedPendingPayment(),
            'items_count' => $cart->items->count(),
            'items' => $items,
            'created_at_human' => $cart->created_at?->format('d/m/Y H:i'),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
            'completed_at_human' => $cart->completed_at?->format('d/m/Y H:i'),
            'inactive_for_minutes' => $cart->inactiveForMinutes(),
            'inactive_for_label' => $cart->inactiveForLabel(),
            'abandoned_at_human' => $cart->abandonedAt()?->timezone('America/Monterrey')->format('d/m/Y H:i'),
            'abandoned_threshold_minutes' => Cart::ABANDONED_AFTER_MINUTES,
            'related_laboratory_purchase' => $purchase ? [
                'id' => $purchase->id,
                'brand_label' => $purchase->brand->label(),
                'created_at_human' => $purchase->created_at?->format('d/m/Y H:i'),
                'total_formatted' => $purchase->formatted_total ?? formattedPrice((float) $purchase->total),
                'admin_url' => route('admin.laboratory-purchases.show', $purchase),
            ] : null,
            'laboratory_appointments' => $appointments,
            'checkout_drafts' => $checkoutDrafts,
            'journey_steps' => $this->serializeJourneySteps($cart, $purchase, $checkoutDrafts),
            'timeline' => $this->serializeCartTimeline($cart, $purchase, $appointments),
            'other_user_carts' => $this->serializeOtherUserCarts($cart),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checkoutDrafts
     * @return list<array{id: string, label: string, at: string|null, status: string}>
     */
    private function serializeJourneySteps(Cart $cart, ?LaboratoryPurchase $purchase, array $checkoutDrafts): array
    {
        $display = $cart->displayStatus();
        $isCompleted = $display === 'completed';
        $isAbandoned = $display === 'abandoned';
        $hasCheckoutSignal = $checkoutDrafts !== []
            || $purchase !== null
            || $cart->hasAppointmentPendingConfirmation()
            || $cart->hasAppointmentConfirmedPendingPayment();
        $purchaseAt = $purchase?->created_at?->format('d/m/Y H:i');
        $completedAt = $cart->completed_at?->format('d/m/Y H:i');
        $checkoutAt = collect($checkoutDrafts)
            ->pluck('updated_at_human')
            ->filter()
            ->first()
            ?? $purchaseAt
            ?? $cart->updated_at?->format('d/m/Y H:i');

        $created = [
            'id' => 'created',
            'label' => 'Carrito creado',
            'at' => $cart->created_at?->format('d/m/Y H:i'),
            'status' => 'completed',
        ];

        $confirmationStatus = match (true) {
            $isCompleted || $purchase !== null => 'completed',
            $hasCheckoutSignal => 'current',
            $isAbandoned => 'upcoming',
            default => 'current',
        };

        $paymentStatus = match (true) {
            $isCompleted || $purchase !== null => 'completed',
            $cart->hasAppointmentConfirmedPendingPayment() => 'current',
            $confirmationStatus === 'current' => 'upcoming',
            default => 'upcoming',
        };

        $orderStatus = match (true) {
            $isCompleted || $purchase !== null => 'completed',
            $paymentStatus === 'completed' => 'current',
            default => 'upcoming',
        };

        $monitoringStatus = match (true) {
            $isCompleted => 'completed',
            $isAbandoned => 'upcoming',
            $orderStatus === 'completed' => 'current',
            default => 'upcoming',
        };

        return [
            $created,
            [
                'id' => 'confirmation',
                'label' => 'Confirmación',
                'at' => $hasCheckoutSignal || $isCompleted ? $checkoutAt : null,
                'status' => $confirmationStatus,
            ],
            [
                'id' => 'payment',
                'label' => 'Pago',
                'at' => $purchaseAt,
                'status' => $paymentStatus,
            ],
            [
                'id' => 'order_sent',
                'label' => 'Pedido enviado',
                'at' => $purchaseAt,
                'status' => $orderStatus,
            ],
            [
                'id' => 'monitoring',
                'label' => 'Monitoreo',
                'at' => $completedAt,
                'status' => $monitoringStatus,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $appointments
     * @return list<array{id: string, label: string, detail: string|null, at: string|null}>
     */
    private function serializeCartTimeline(Cart $cart, ?LaboratoryPurchase $purchase, array $appointments): array
    {
        $events = collect([
            [
                'id' => 'created',
                'label' => 'Carrito creado',
                'detail' => $cart->type === MonitoringCartType::Lab ? 'Laboratorio' : 'Farmacia',
                'at' => $cart->created_at?->format('d/m/Y H:i'),
                'sort' => $cart->created_at?->timestamp ?? 0,
            ],
        ]);

        if ($cart->displayStatus() === 'abandoned' && $cart->abandonedAt()) {
            $events->push([
                'id' => 'abandoned',
                'label' => 'Marcado como abandonado',
                'detail' => 'Sin actividad ≥ '.$cart::ABANDONED_AFTER_MINUTES.' min',
                'at' => $cart->abandonedAt()->timezone('America/Monterrey')->format('d/m/Y H:i'),
                'sort' => $cart->abandonedAt()->timestamp,
            ]);
        }

        foreach ($appointments as $appointment) {
            if (! empty($appointment['request_saved_at'])) {
                $events->push([
                    'id' => 'appointment-request-'.$appointment['id'],
                    'label' => 'Solicitud de cita',
                    'detail' => $appointment['brand_label'] ?? null,
                    'at' => $appointment['request_saved_at'],
                    'sort' => $this->timelineSortKey($appointment['request_saved_at']),
                ]);
            }

            if (! empty($appointment['is_confirmed']) && ! empty($appointment['confirmed_at_human'])) {
                $events->push([
                    'id' => 'appointment-confirmed-'.$appointment['id'],
                    'label' => 'Cita confirmada',
                    'detail' => $appointment['brand_label'] ?? null,
                    'at' => $appointment['confirmed_at_human'],
                    'sort' => $this->timelineSortKey($appointment['confirmed_at_human']),
                ]);
            }
        }

        if ($purchase) {
            $events->push([
                'id' => 'purchase',
                'label' => 'Compra registrada',
                'detail' => 'Pedido #'.$purchase->id,
                'at' => $purchase->created_at?->format('d/m/Y H:i'),
                'sort' => $purchase->created_at?->timestamp ?? 0,
            ]);
        }

        if ($cart->completed_at) {
            $events->push([
                'id' => 'completed',
                'label' => 'Carrito comprado',
                'detail' => 'Monitoreo completado',
                'at' => $cart->completed_at->format('d/m/Y H:i'),
                'sort' => $cart->completed_at->timestamp,
            ]);
        } elseif ($cart->updated_at && ! $cart->created_at?->equalTo($cart->updated_at)) {
            $events->push([
                'id' => 'updated',
                'label' => 'Última actividad',
                'detail' => null,
                'at' => $cart->updated_at->format('d/m/Y H:i'),
                'sort' => $cart->updated_at->timestamp,
            ]);
        }

        return $events
            ->sortBy('sort')
            ->values()
            ->map(fn (array $event) => [
                'id' => $event['id'],
                'label' => $event['label'],
                'detail' => $event['detail'],
                'at' => $event['at'],
            ])
            ->all();
    }

    private function timelineSortKey(?string $humanDate): int
    {
        if (! filled($humanDate)) {
            return 0;
        }

        try {
            return Carbon::createFromFormat('d/m/Y H:i', $humanDate, 'America/Monterrey')->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeOtherUserCarts(Cart $cart): array
    {
        if (! $cart->user_id) {
            return [];
        }

        return Cart::query()
            ->where('user_id', $cart->user_id)
            ->where('id', '!=', $cart->id)
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(function (Cart $other) {
                $status = $other->displayStatus();

                return [
                    'id' => $other->id,
                    'type' => $other->type->value,
                    'type_label' => $other->type === MonitoringCartType::Lab ? 'Laboratorio' : 'Farmacia',
                    'display_status' => $status,
                    'display_status_label' => $other->displayStatusLabel(),
                    'total_formatted' => formattedPrice((float) $other->total),
                    'updated_at_human' => $other->updated_at?->format('d/m/Y H:i'),
                    'admin_url' => route('admin.carts.show', $other),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCheckoutDrafts(Cart $cart): array
    {
        $fromDrafts = $this->mapCheckoutDrafts($cart, detailed: true);

        if ($fromDrafts !== []) {
            return $fromDrafts;
        }

        if ($cart->status === MonitoringCartStatus::Completed) {
            return $this->synthesizeCompletedCheckoutEntries($cart, detailed: true);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCheckoutSummaryForRow(Cart $cart): array
    {
        $fromDrafts = $this->mapCheckoutDrafts($cart, detailed: false);

        if ($fromDrafts !== []) {
            return $fromDrafts;
        }

        if ($cart->status === MonitoringCartStatus::Completed) {
            return $this->synthesizeCompletedCheckoutEntries($cart, detailed: false);
        }

        return [];
    }

    /**
     * Tras la compra se eliminan los drafts de checkout; reconstruimos el avance
     * desde la compra (y cita vinculada) para no mostrar "Sin avance" en Comprado.
     *
     * @return list<array<string, mixed>>
     */
    private function synthesizeCompletedCheckoutEntries(Cart $cart, bool $detailed): array
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return [];
        }

        $brands = collect($cart->labBrands());

        if ($brands->isEmpty()) {
            $purchase = $cart->relatedLaboratoryPurchase();
            if ($purchase?->brand) {
                $brands = collect([[
                    'value' => $purchase->brand->value,
                    'label' => $purchase->brand->label(),
                ]]);
            }
        }

        if ($brands->isEmpty()) {
            $entry = [
                'id' => 'completed-checkout',
                'brand_label' => null,
                'checkout_step' => 'completed',
                'checkout_step_label' => 'Completado',
                'is_completed' => true,
                'patient_name' => 'Registrado en compra',
                'address_short' => 'Registrada',
                'payment_method_label' => 'Pagado',
                'appointment' => null,
            ];

            if ($detailed) {
                $entry['updated_at_human'] = $cart->completed_at
                    ?->timezone('America/Monterrey')
                    ?->format('d/m/Y H:i');
                $entry['patient'] = null;
                $entry['address'] = null;
            }

            return [$entry];
        }

        return $brands
            ->map(function (array $brand) use ($cart, $detailed) {
                $brandEnum = LaboratoryBrand::from($brand['value']);
                $purchase = $this->purchaseForBrand($cart, $brandEnum);
                $appointment = $this->appointmentForCompletedCheckout($cart, $brandEnum, $purchase);

                $patientName = $purchase?->full_name
                    ?: ($appointment?->patient_full_name ?: null);
                $addressShort = $this->purchaseAddressShort($purchase);
                $paymentLabel = $this->transactionPaymentLabel($purchase?->transactions?->first());

                // La compra ya ocurrió: el checkout se completó aunque el draft se haya borrado.
                $patientName ??= 'Registrado en compra';
                $addressShort ??= 'Registrada';
                $paymentLabel ??= 'Pagado';

                $appointmentData = $appointment
                    ? $this->serializeLaboratoryAppointmentForAdmin($appointment, compact: ! $detailed)
                    : null;

                $entry = [
                    'id' => 'completed-'.$brand['value'].($purchase ? '-'.$purchase->id : ''),
                    'brand_label' => $brand['label'],
                    'checkout_step' => 'completed',
                    'checkout_step_label' => 'Completado',
                    'is_completed' => true,
                    'patient_name' => $patientName,
                    'address_short' => $addressShort,
                    'payment_method_label' => $paymentLabel,
                    'appointment' => $appointmentData,
                ];

                if ($detailed) {
                    $entry['updated_at_human'] = ($purchase?->created_at ?? $cart->completed_at)
                        ?->timezone('America/Monterrey')
                        ?->format('d/m/Y H:i');
                    $entry['patient'] = $purchase ? [
                        'full_name' => $purchase->full_name,
                        'phone' => $purchase->full_phone ?? $purchase->phone,
                        'formatted_birth_date' => $purchase->formatted_birth_date,
                        'formatted_gender' => $purchase->formatted_gender,
                    ] : ($appointment && filled($appointment->patient_name) ? [
                        'full_name' => $appointment->patient_full_name,
                        'phone' => null,
                        'formatted_birth_date' => null,
                        'formatted_gender' => null,
                    ] : null);
                    $entry['address'] = $purchase && $addressShort ? [
                        'formatted_address' => $addressShort,
                        'full_address' => $addressShort,
                    ] : null;
                }

                return $entry;
            })
            ->values()
            ->all();
    }

    private function purchaseForBrand(Cart $cart, LaboratoryBrand $brand): ?LaboratoryPurchase
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return null;
        }

        $purchases = $customer->relationLoaded('laboratoryPurchases')
            ? $customer->laboratoryPurchases
            : $customer->laboratoryPurchases()->with('transactions')->get();

        $forBrand = $purchases->filter(
            fn (LaboratoryPurchase $purchase) => $purchase->brand === $brand,
        );

        if ($cart->completed_at) {
            $start = $cart->completed_at->copy()->subDay();
            $end = $cart->completed_at->copy()->addDay();
            $inWindow = $forBrand->filter(
                fn (LaboratoryPurchase $purchase) => $purchase->created_at
                    && $purchase->created_at->between($start, $end),
            );

            if ($inWindow->isNotEmpty()) {
                return $inWindow->sortByDesc('created_at')->first();
            }
        }

        return $forBrand->sortByDesc('created_at')->first();
    }

    private function appointmentForCompletedCheckout(
        Cart $cart,
        LaboratoryBrand $brand,
        ?LaboratoryPurchase $purchase,
    ): ?LaboratoryAppointment {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return null;
        }

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        if ($purchase) {
            $linked = $appointments->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->laboratory_purchase_id === (int) $purchase->id,
            );

            if ($linked) {
                return $linked;
            }
        }

        if ($cart->completed_at) {
            $start = $cart->completed_at->copy()->subDay();
            $end = $cart->completed_at->copy()->addDay();

            return $appointments
                ->filter(
                    fn (LaboratoryAppointment $appointment) => $appointment->brand === $brand
                        && $appointment->updated_at
                        && $appointment->updated_at->between($start, $end),
                )
                ->sortByDesc('updated_at')
                ->first();
        }

        return $this->appointmentForBrand($cart, $brand);
    }

    private function purchaseAddressShort(?LaboratoryPurchase $purchase): ?string
    {
        if ($purchase === null) {
            return null;
        }

        $parts = array_filter([
            trim("{$purchase->street} {$purchase->number}"),
            $purchase->neighborhood,
            $purchase->city,
            $purchase->state,
        ]);

        $text = implode(', ', $parts);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 48
            ? mb_substr($text, 0, 45).'…'
            : $text;
    }

    private function transactionPaymentLabel(?Transaction $transaction): ?string
    {
        if ($transaction === null) {
            return null;
        }

        $tokenInfo = $transaction->details['token_info'] ?? null;
        if (is_array($tokenInfo) && filled($tokenInfo['card_last_four'] ?? null)) {
            $brand = ucfirst(strtolower((string) ($tokenInfo['card_brand'] ?? 'Tarjeta')));

            return sprintf('%s •••• %s', $brand, $tokenInfo['card_last_four']);
        }

        return match (strtolower((string) $transaction->payment_method)) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            'efevoopay' => 'Tarjeta',
            default => filled($transaction->payment_method)
                ? ucfirst((string) $transaction->payment_method)
                : 'Pagado',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapCheckoutDrafts(Cart $cart, bool $detailed): array
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return [];
        }

        $brandValues = collect($cart->labBrands())->pluck('value')->filter()->values();

        $drafts = $customer->relationLoaded('laboratoryCheckoutDrafts')
            ? $customer->laboratoryCheckoutDrafts
            : $customer->laboratoryCheckoutDrafts()->with(['contact', 'address'])->get();

        return $drafts
            ->when(
                $brandValues->isNotEmpty(),
                fn ($rows) => $rows->filter(
                    fn (LaboratoryCheckoutDraft $draft) => $brandValues->contains($draft->laboratory_brand->value),
                ),
            )
            ->sortByDesc('updated_at')
            ->map(function (LaboratoryCheckoutDraft $draft) use ($customer, $cart, $detailed) {
                $appointment = $this->appointmentForBrand($cart, $draft->laboratory_brand);
                $appointmentData = $appointment
                    ? $this->serializeLaboratoryAppointmentForAdmin($appointment, compact: ! $detailed)
                    : null;

                $entry = [
                    'id' => $draft->id,
                    'brand_label' => $draft->laboratory_brand->label(),
                    'checkout_step' => $draft->checkout_step,
                    'checkout_step_label' => $this->checkoutStepLabel($draft->checkout_step),
                    'patient_name' => $draft->contact?->full_name,
                    'address_short' => $this->shortAddressLabel($draft->address),
                    'payment_method_label' => $this->paymentMethodLabel(
                        $draft->payment_method,
                        $customer,
                    ),
                    'appointment' => $appointmentData,
                ];

                if ($detailed) {
                    $entry['updated_at_human'] = $draft->updated_at?->format('d/m/Y H:i');
                    $entry['patient'] = $draft->contact ? [
                        'full_name' => $draft->contact->full_name,
                        'phone' => $draft->contact->phone_for_display ?? $draft->contact->phone,
                        'formatted_birth_date' => $draft->contact->formatted_birth_date,
                        'formatted_gender' => $draft->contact->formatted_gender,
                    ] : null;
                    $entry['address'] = $draft->address ? [
                        'formatted_address' => $draft->address->formatted_address,
                        'full_address' => $draft->address->full_address,
                    ] : null;
                }

                return $entry;
            })
            ->values()
            ->all();
    }

    private function shortAddressLabel(?\App\Models\Address $address): ?string
    {
        if (! $address) {
            return null;
        }

        $text = trim((string) ($address->formatted_address ?: $address->full_address));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 48
            ? mb_substr($text, 0, 45).'…'
            : $text;
    }

    private function appointmentForBrand(Cart $cart, \App\Enums\LaboratoryBrand $brand): ?LaboratoryAppointment
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return null;
        }

        $explicit = $cart->relationLoaded('laboratoryAppointments')
            ? $cart->laboratoryAppointments
            : $cart->laboratoryAppointments()->get();

        $explicitForBrand = $explicit
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->brand === $brand)
            ->sortByDesc('updated_at')
            ->first();

        if ($explicitForBrand) {
            return $explicitForBrand;
        }

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $appointments
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->cart_id === null
                && $appointment->brand === $brand)
            ->sortByDesc('updated_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLaboratoryAppointmentForAdmin(
        LaboratoryAppointment $appointment,
        bool $compact = false,
    ): array {
        $data = [
            'id' => $appointment->id,
            'brand_label' => $appointment->brand->label(),
            'patient_name' => $appointment->patient_full_name,
            'is_confirmed' => $appointment->confirmed_at !== null,
            'confirmed_at_human' => $appointment->confirmed_at?->format('d/m/Y H:i'),
            'appointment_date_human' => $appointment->formatted_appointment_date,
            'has_linked_purchase' => $appointment->laboratory_purchase_id !== null,
            'request_saved_at' => $appointment->formatted_request_saved_at,
            'callback_availability_range' => $appointment->formatted_callback_availability_range,
            'callback_comment' => filled($appointment->patient_callback_comment)
                ? $appointment->patient_callback_comment
                : null,
            'callback_comment_short' => $this->shortText($appointment->patient_callback_comment, 60),
            'has_callback_info' => (bool) $appointment->has_left_callback_info,
            'has_phone_call_intent' => $appointment->phone_call_intent_at !== null,
            'phone_call_intent_at_human' => $appointment->formatted_phone_call_intent_at,
            'updated_at_human' => $appointment->updated_at?->format('d/m/Y H:i'),
            'admin_url' => route('admin.laboratory-appointments.show', $appointment),
        ];

        if ($compact) {
            $result = collect($data)->only([
                'brand_label',
                'request_saved_at',
                'callback_availability_range',
                'callback_comment_short',
                'has_callback_info',
                'has_phone_call_intent',
                'phone_call_intent_at_human',
            ])->filter(fn ($value) => $value !== null && $value !== false)->all();

            $result['is_confirmed'] = $data['is_confirmed'];
            $result['has_linked_purchase'] = $data['has_linked_purchase'];

            return $result;
        }

        return $data;
    }

    private function shortText(?string $text, int $maxLength): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $maxLength
            ? mb_substr($text, 0, $maxLength - 1).'…'
            : $text;
    }

    private function checkoutStepLabel(string $step): string
    {
        return match ($step) {
            'patient' => 'Paciente',
            'address' => 'Dirección',
            'payment' => 'Método de pago',
            'appointment' => 'Cita',
            'confirmation' => 'Confirmación',
            'completed' => 'Completado',
            default => $step,
        };
    }

    private function paymentMethodLabel(?string $paymentMethod, \App\Models\Customer $customer): ?string
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return null;
        }

        return match ($paymentMethod) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            default => $this->efevooTokenPaymentLabel($paymentMethod, $customer),
        };
    }

    private function efevooTokenPaymentLabel(string $paymentMethod, \App\Models\Customer $customer): string
    {
        if (! ctype_digit($paymentMethod)) {
            return $paymentMethod;
        }

        $token = EfevooToken::query()
            ->where('customer_id', $customer->id)
            ->where('id', (int) $paymentMethod)
            ->first();

        if (! $token) {
            return 'Tarjeta #'.$paymentMethod;
        }

        return sprintf(
            '%s •••• %s',
            ucfirst(strtolower((string) $token->card_brand)),
            $token->card_last_four,
        );
    }
}
