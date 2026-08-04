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
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            'start_date',
            'end_date',
        ]))->filter(fn ($v) => $v !== null && $v !== '')->all();

        $start = ! empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
        $end = ! empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;

        $query = Cart::query()
            ->with([
                'items',
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

        $metricsBase = Cart::query()
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($start, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn ($q, $d) => $q->where('updated_at', '<=', $d));

        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $metrics = [
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
        ];

        $den = $metrics['completed'] + $metrics['abandoned'];
        $metrics['conversion_percent'] = $den > 0
            ? round(100 * $metrics['completed'] / $den, 1)
            : null;

        $carts->getCollection()->transform(fn (Cart $cart) => $this->serializeCartRow($cart));

        return Inertia::render('Admin/Carts', [
            'carts' => $carts,
            'filters' => $filters,
            'metrics' => $metrics,
            'abandonedThresholdMinutes' => Cart::ABANDONED_AFTER_MINUTES,
            'canViewCartDetails' => $request->user()->administrator->hasPermissionTo('view cart details'),
            'canExport' => $request->user()->administrator->hasPermissionTo('view carts'),
        ]);
    }

    public function show(Request $request, Cart $cart)
    {
        $request->user()->administrator->hasPermissionTo('view cart details') || abort(403);

        $cart->load([
            'items',
            'user.customer.laboratoryAppointments',
            'user.customer.laboratoryCheckoutDrafts.contact',
            'user.customer.laboratoryCheckoutDrafts.address',
            'user.customer.laboratoryPurchases.transactions',
        ]);

        return Inertia::render('Admin/CartShow', [
            'cart' => $this->serializeCartDetail($cart),
        ]);
    }

    private function serializeCartRow(Cart $cart): array
    {
        $display = $cart->displayStatus();

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'lab_brands' => $cart->labBrands(),
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
        ];
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

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $appointments
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->brand === $brand)
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
