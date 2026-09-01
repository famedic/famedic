<?php

namespace App\Services\Carts;

use App\Enums\CartCheckoutFlowType;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;
use Carbon\CarbonInterface;

/**
 * Interpretación administrativa unificada de etapas, journey y flujo de checkout.
 */
class CartAdminStageInterpreter
{
    public function __construct(
        private CartCheckoutFlowResolver $flowResolver,
        private CartUserActivityResolver $activityResolver,
        private CartAbandonmentService $abandonmentService,
        private LaboratoryAppointmentPaymentValidity $appointmentPaymentValidity,
    ) {}

    /**
     * @return array{
     *     flow: CartCheckoutFlowType,
     *     flow_label: string,
     *     flow_short_label: string,
     *     flow_confidence: string,
     *     flow_brand: string|null,
     *     requires_appointment: bool,
     *     last_user_activity_at: CarbonInterface,
     *     last_user_activity_human: string|null,
     *     inactive_for_minutes: int|null,
     *     inactive_for_label: string|null,
     *     display_status: string,
     *     display_status_label: string,
     *     open_abandonment_episode: int|null,
     *     journey_step_index: int|null,
     *     journey_step_total: int,
     * }
     */
    public function context(Cart $cart, ?LaboratoryBrand $brand = null): array
    {
        $flowMeta = $this->flowResolver->resolve($cart, $brand);
        $lastActivity = $this->activityResolver->lastUserActivityAt($cart);
        $requiresAppointment = $this->cartRequiresAppointment($cart);

        return [
            'flow' => $flowMeta['flow'],
            'flow_label' => $flowMeta['label'],
            'flow_short_label' => $flowMeta['flow']->shortLabel(),
            'flow_confidence' => $flowMeta['confidence'],
            'flow_brand' => $flowMeta['brand'],
            'requires_appointment' => $requiresAppointment,
            'last_user_activity_at' => $lastActivity,
            'last_user_activity_human' => $lastActivity->timezone('America/Monterrey')->format('d/m/Y H:i'),
            'inactive_for_minutes' => $this->inactiveMinutes($cart, $lastActivity),
            'inactive_for_label' => $this->inactiveLabel($cart, $lastActivity),
            'display_status' => $this->adminDisplayStatus($cart, $lastActivity),
            'display_status_label' => $this->adminDisplayStatusLabel($cart, $lastActivity),
            'open_abandonment_episode' => $this->abandonmentService->openAbandonedEpisode($cart),
            'journey_step_index' => null,
            'journey_step_total' => $this->journeyStepTotal($flowMeta['flow'], $requiresAppointment),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checkoutEntries
     * @return list<array{key: string, label: string, state: string, detail: string}>
     */
    public function journey(
        Cart $cart,
        array $checkoutEntries,
        ?LaboratoryAppointment $appointment,
        ?LaboratoryPurchase $purchase,
        ?array $paymentInsight,
        ?array $finalPayment = null,
    ): array {
        $ctx = $this->context($cart);
        $flow = $ctx['flow'];
        $requiresAppointment = $ctx['requires_appointment'];
        $definitions = $this->journeyDefinitions($flow, $requiresAppointment);

        $hasItems = $cart->items->isNotEmpty();
        $hasPatient = collect($checkoutEntries)->contains(fn (array $entry) => filled($entry['patient_name'] ?? null))
            || $this->cartHasEvent($cart, CartEventType::PatientSelected)
            || $purchase !== null;
        $hasAddress = collect($checkoutEntries)->contains(fn (array $entry) => filled($entry['address_short'] ?? null))
            || $this->cartHasEvent($cart, CartEventType::AddressSelected)
            || $purchase !== null;
        $isCompleted = $cart->status === MonitoringCartStatus::Completed && $purchase !== null;
        $explicitAppointment = $appointment ?? $cart->explicitLaboratoryAppointments()->first();

        $steps = [];
        foreach ($definitions as $definition) {
            $steps[] = match ($definition['key']) {
                'items' => [
                    'key' => 'items',
                    'label' => 'Estudios',
                    'state' => $hasItems ? 'completed' : 'current',
                    'detail' => $hasItems ? $this->itemsLabel($cart) : 'Sin items',
                ],
                'patient' => [
                    'key' => 'patient',
                    'label' => 'Paciente',
                    'state' => $hasPatient ? 'completed' : 'pending',
                    'detail' => $hasPatient ? 'Registrado' : 'No registrado',
                ],
                'address' => [
                    'key' => 'address',
                    'label' => 'Dirección',
                    'state' => $hasAddress ? 'completed' : 'pending',
                    'detail' => $hasAddress ? 'Registrada' : 'No registrada',
                ],
                'appointment' => $this->appointmentStep($requiresAppointment, $explicitAppointment, $cart),
                'payment' => $this->paymentStep(
                    $cart,
                    $checkoutEntries,
                    $explicitAppointment,
                    $flow,
                    $requiresAppointment,
                    $paymentInsight,
                    $finalPayment,
                ),
                'purchase' => [
                    'key' => 'purchase',
                    'label' => 'Compra',
                    'state' => $isCompleted ? 'completed' : 'pending',
                    'detail' => $isCompleted ? 'Compra registrada' : 'Sin compra',
                ],
                default => $definition,
            };
        }

        return $steps;
    }

    /**
     * @return array{key: string, label: string, detail: string|null, tone: string}
     */
    public function currentStage(Cart $cart, ?array $paymentInsight = null): array
    {
        if ($this->adminDisplayStatus($cart, $this->activityResolver->lastUserActivityAt($cart)) === 'completed') {
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

        $appointment = $cart->explicitLaboratoryAppointments()->first()
            ?? $cart->laboratoryAppointmentsForDisplay()->first();

        if ($appointment) {
            if ($appointment->confirmed_at !== null && $appointment->laboratory_purchase_id === null) {
                if ($this->hasPaymentMethodSelectedEvent($cart)) {
                    return [
                        'key' => 'payment_method_selected',
                        'label' => 'Método seleccionado',
                        'detail' => $this->selectedPaymentMethodLabel($cart) ?? 'Registrado',
                        'tone' => 'sky',
                    ];
                }

                if ($this->appointmentPaymentValidity->isValidForPayment($appointment, (int) $cart->id)) {
                    return [
                        'key' => 'payment_available',
                        'label' => 'Pago disponible',
                        'detail' => 'Cita confirmada',
                        'tone' => 'green',
                    ];
                }

                return [
                    'key' => 'appointment_confirmed_blocked',
                    'label' => 'Cita confirmada',
                    'detail' => $this->paymentBlockReason($appointment, (int) $cart->id),
                    'tone' => 'amber',
                ];
            }

            if ($appointment->confirmed_at === null) {
                return [
                    'key' => 'appointment_pending',
                    'label' => 'Cita pendiente',
                    'detail' => 'Esperando confirmación del concierge',
                    'tone' => 'amber',
                ];
            }
        }

        if ($this->hasPaymentMethodSelectedEvent($cart)) {
            return [
                'key' => 'payment_method_selected',
                'label' => 'Método seleccionado',
                'detail' => $this->selectedPaymentMethodLabel($cart) ?? 'Registrado',
                'tone' => 'sky',
            ];
        }

        $draft = $this->checkoutDraft($cart);
        if ($draft !== null) {
            return match ($draft->checkout_step) {
                'patient' => ['key' => 'patient', 'label' => 'Paciente', 'detail' => 'En selección', 'tone' => 'slate'],
                'address' => ['key' => 'address', 'label' => 'Dirección', 'detail' => 'En selección', 'tone' => 'slate'],
                'appointment' => ['key' => 'appointment', 'label' => 'Cita', 'detail' => 'En gestión', 'tone' => 'slate'],
                'payment' => ['key' => 'payment', 'label' => 'Pago', 'detail' => 'En selección', 'tone' => 'slate'],
                default => ['key' => 'checkout', 'label' => 'Checkout', 'detail' => null, 'tone' => 'slate'],
            };
        }

        if ($this->adminDisplayStatus($cart, $this->activityResolver->lastUserActivityAt($cart)) === 'abandoned') {
            return [
                'key' => 'abandoned',
                'label' => 'Carrito abandonado',
                'detail' => null,
                'tone' => 'zinc',
            ];
        }

        return [
            'key' => 'no_progress',
            'label' => 'Sin avance',
            'detail' => null,
            'tone' => 'zinc',
        ];
    }

    /**
     * @return array{payable: bool, reason: string|null}
     */
    public function appointmentPayableState(?LaboratoryAppointment $appointment, int $cartId): array
    {
        if ($appointment === null) {
            return ['payable' => false, 'reason' => 'Sin cita'];
        }

        if ($this->appointmentPaymentValidity->isValidForPayment($appointment, $cartId)) {
            return ['payable' => true, 'reason' => null];
        }

        return [
            'payable' => false,
            'reason' => $this->paymentBlockReason($appointment, $cartId),
        ];
    }

    public function exportFlowLabel(Cart $cart, ?LaboratoryBrand $brand = null): string
    {
        $ctx = $this->context($cart, $brand);
        $label = $ctx['flow_label'];
        if ($ctx['flow_confidence'] === 'inferred') {
            return $label.' (inferido)';
        }
        if ($ctx['flow_confidence'] === 'eligibility_fallback') {
            return $label.' (estimado)';
        }
        if ($ctx['flow'] === CartCheckoutFlowType::Unknown) {
            return $label;
        }

        return $label;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function journeyDefinitions(CartCheckoutFlowType $flow, bool $requiresAppointment): array
    {
        $base = [
            ['key' => 'items', 'label' => 'Estudios'],
            ['key' => 'patient', 'label' => 'Paciente'],
            ['key' => 'address', 'label' => 'Dirección'],
        ];

        if ($flow === CartCheckoutFlowType::AppointmentFirst && $requiresAppointment) {
            return array_merge($base, [
                ['key' => 'appointment', 'label' => 'Cita'],
                ['key' => 'payment', 'label' => 'Pago'],
                ['key' => 'purchase', 'label' => 'Compra'],
            ]);
        }

        if ($requiresAppointment) {
            return array_merge($base, [
                ['key' => 'payment', 'label' => 'Pago'],
                ['key' => 'appointment', 'label' => 'Cita'],
                ['key' => 'purchase', 'label' => 'Compra'],
            ]);
        }

        return array_merge($base, [
            ['key' => 'payment', 'label' => 'Pago'],
            ['key' => 'purchase', 'label' => 'Compra'],
        ]);
    }

    private function journeyStepTotal(CartCheckoutFlowType $flow, bool $requiresAppointment): int
    {
        return count($this->journeyDefinitions($flow, $requiresAppointment));
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string}
     */
    private function appointmentStep(bool $requiresAppointment, ?LaboratoryAppointment $appointment, Cart $cart): array
    {
        if (! $requiresAppointment) {
            return [
                'key' => 'appointment',
                'label' => 'Cita',
                'state' => 'pending',
                'detail' => 'No aplica',
            ];
        }

        if ($appointment?->confirmed_at !== null) {
            $detail = $appointment->laboratory_purchase_id === null
                ? 'Cita confirmada · pago disponible'
                : 'Confirmada';

            return [
                'key' => 'appointment',
                'label' => 'Cita',
                'state' => 'completed',
                'detail' => $detail,
            ];
        }

        if ($appointment !== null || $this->cartHasEvent($cart, CartEventType::AppointmentRequested)) {
            return [
                'key' => 'appointment',
                'label' => 'Cita',
                'state' => 'current',
                'detail' => 'Esperando confirmación del concierge',
            ];
        }

        return [
            'key' => 'appointment',
            'label' => 'Cita',
            'state' => 'pending',
            'detail' => 'No iniciada',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checkoutEntries
     * @return array{key: string, label: string, state: string, detail: string}
     */
    private function paymentStep(
        Cart $cart,
        array $checkoutEntries,
        ?LaboratoryAppointment $appointment,
        CartCheckoutFlowType $flow,
        bool $requiresAppointment,
        ?array $paymentInsight,
        ?array $finalPayment,
    ): array {
        if ($finalPayment !== null) {
            return [
                'key' => 'payment',
                'label' => 'Pago',
                'state' => 'completed',
                'detail' => $finalPayment['method_label'] ?? 'Completado',
            ];
        }

        $journeyPaymentInsight = $this->journeyEligiblePaymentInsight($paymentInsight);

        if ($journeyPaymentInsight !== null) {
            if (($journeyPaymentInsight['confidence'] ?? null) === 'ambiguous') {
                return [
                    'key' => 'payment',
                    'label' => 'Pago',
                    'state' => 'pending',
                    'detail' => 'Información no determinada',
                ];
            }

            $attemptStatus = match ($journeyPaymentInsight['status'] ?? null) {
                PaymentAttempt::STATUS_APPROVED => ['state' => 'completed', 'detail' => 'Aprobado'],
                PaymentAttempt::STATUS_DECLINED => ['state' => 'failed', 'detail' => 'Rechazado'],
                PaymentAttempt::STATUS_ERROR => ['state' => 'failed', 'detail' => 'Error técnico'],
                PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => ['state' => 'current', 'detail' => 'Pendiente'],
                default => null,
            };

            if ($attemptStatus !== null) {
                return array_merge(['key' => 'payment', 'label' => 'Pago'], $attemptStatus);
            }
        }

        if ($requiresAppointment && $flow === CartCheckoutFlowType::AppointmentFirst) {
            if ($appointment === null || $appointment->confirmed_at === null) {
                return [
                    'key' => 'payment',
                    'label' => 'Pago',
                    'state' => 'pending',
                    'detail' => 'Pago bloqueado',
                ];
            }

            if (! $this->appointmentPaymentValidity->isValidForPayment($appointment, (int) $cart->id)) {
                return [
                    'key' => 'payment',
                    'label' => 'Pago',
                    'state' => 'failed',
                    'detail' => $this->paymentBlockReason($appointment, (int) $cart->id),
                ];
            }
        }

        if ($this->hasPaymentMethodSelectedEvent($cart)) {
            $label = $this->selectedPaymentMethodLabel($cart)
                ?? $this->draftPaymentMethodLabel($cart, $checkoutEntries);

            if ($label !== null) {
                return [
                    'key' => 'payment',
                    'label' => 'Pago',
                    'state' => 'current',
                    'detail' => 'Método seleccionado: '.$label,
                ];
            }
        }

        if ($requiresAppointment
            && $flow === CartCheckoutFlowType::AppointmentFirst
            && $appointment?->confirmed_at !== null
            && $this->appointmentPaymentValidity->isValidForPayment($appointment, (int) $cart->id)) {
            return [
                'key' => 'payment',
                'label' => 'Pago',
                'state' => 'current',
                'detail' => 'Pago disponible',
            ];
        }

        $draftLabel = $this->draftPaymentMethodLabel($cart, $checkoutEntries);
        if ($draftLabel !== null && ! (
            $requiresAppointment
            && $flow === CartCheckoutFlowType::AppointmentFirst
            && ($appointment === null || $appointment->confirmed_at === null)
        )) {
            return [
                'key' => 'payment',
                'label' => 'Pago',
                'state' => 'current',
                'detail' => 'Método seleccionado: '.$draftLabel,
            ];
        }

        return [
            'key' => 'payment',
            'label' => 'Pago',
            'state' => 'pending',
            'detail' => 'No iniciado',
        ];
    }

    private function adminDisplayStatus(Cart $cart, CarbonInterface $lastActivity): string
    {
        if ($cart->status === MonitoringCartStatus::Completed) {
            return 'completed';
        }

        if ($cart->isEmptyActiveMonitoringCart()) {
            return 'empty';
        }

        if ($cart->hasAppointmentPendingConfirmation()) {
            return 'active';
        }

        if ($this->abandonmentService->wasInactiveBeyondThreshold($lastActivity)) {
            return 'abandoned';
        }

        return 'active';
    }

    private function adminDisplayStatusLabel(Cart $cart, CarbonInterface $lastActivity): string
    {
        return match ($this->adminDisplayStatus($cart, $lastActivity)) {
            'completed' => 'Comprado',
            'abandoned' => 'Abandonado',
            'empty' => 'Vacío (histórico)',
            default => 'Activo',
        };
    }

    private function inactiveMinutes(Cart $cart, CarbonInterface $lastActivity): ?int
    {
        if ($cart->status === MonitoringCartStatus::Completed) {
            return null;
        }

        return max(0, (int) floor(max(0, now()->getTimestamp() - $lastActivity->getTimestamp()) / 60));
    }

    private function inactiveLabel(Cart $cart, CarbonInterface $lastActivity): ?string
    {
        $minutes = $this->inactiveMinutes($cart, $lastActivity);
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return $remainder > 0 ? "{$hours} h {$remainder} min" : "{$hours} h";
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

    private function cartHasEvent(Cart $cart, CartEventType $type): bool
    {
        $events = $cart->relationLoaded('events') ? $cart->events : null;

        if ($events !== null) {
            return $events->contains(fn ($event) => ($event->event?->value ?? (string) $event->event) === $type->value);
        }

        return $cart->events()->where('event', $type->value)->exists();
    }

    private function hasPaymentMethodSelectedEvent(Cart $cart): bool
    {
        return $this->cartHasEvent($cart, CartEventType::PaymentMethodSelected);
    }

    private function selectedPaymentMethodLabel(Cart $cart): ?string
    {
        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->orderByDesc('occurred_at')->orderByDesc('id')->get();

        $event = $events
            ->first(fn ($row) => ($row->event?->value ?? (string) $row->event) === CartEventType::PaymentMethodSelected->value);

        if ($event === null) {
            return null;
        }

        $metadata = $event->metadata ?? [];
        $gateway = (string) ($metadata['gateway'] ?? $metadata['payment_method_type'] ?? '');

        return $this->gatewayDisplayLabel($gateway);
    }

    /**
     * @param  list<array<string, mixed>>  $checkoutEntries
     */
    private function draftPaymentMethodLabel(Cart $cart, array $checkoutEntries): ?string
    {
        foreach ($checkoutEntries as $entry) {
            if (filled($entry['payment_method_label'] ?? null)) {
                return (string) $entry['payment_method_label'];
            }
        }

        $draft = $this->checkoutDraft($cart);
        if ($draft?->payment_method === null) {
            return null;
        }

        return $this->paymentMethodLabelFromDraft($draft->payment_method, $cart);
    }

    private function paymentMethodLabelFromDraft(string $paymentMethod, Cart $cart): string
    {
        return match ($paymentMethod) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            default => ctype_digit($paymentMethod)
                ? $this->efevooTokenLabel((int) $paymentMethod, $cart)
                : ucfirst($paymentMethod),
        };
    }

    private function efevooTokenLabel(int $tokenId, Cart $cart): string
    {
        $customerId = $cart->user?->customer?->id;
        if ($customerId === null) {
            return 'Tarjeta #'.$tokenId;
        }

        $token = EfevooToken::query()
            ->where('customer_id', $customerId)
            ->where('id', $tokenId)
            ->first();

        if ($token === null) {
            return 'Tarjeta #'.$tokenId;
        }

        return sprintf(
            '%s •••• %s',
            ucfirst(strtolower((string) $token->card_brand)),
            $token->card_last_four,
        );
    }

    private function gatewayDisplayLabel(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            'efevoopay' => 'Tarjeta',
            default => $gateway !== '' ? ucfirst($gateway) : 'Método',
        };
    }

    private function paymentBlockReason(LaboratoryAppointment $appointment, int $cartId): string
    {
        if (! $this->appointmentPaymentValidity->isConfirmed($appointment)) {
            return 'Pago bloqueado: cita sin confirmar';
        }

        if ($appointment->trashed()) {
            return 'Pago bloqueado: cita cancelada';
        }

        if ($appointment->laboratory_purchase_id !== null) {
            return 'Pago bloqueado: cita ya pagada';
        }

        if ($appointment->cart_id !== null && (int) $appointment->cart_id !== $cartId) {
            return 'Pago bloqueado: cita de otro carrito';
        }

        if (! $this->appointmentPaymentValidity->hasScheduledDate($appointment)) {
            return 'Pago bloqueado: sin fecha programada';
        }

        if ($this->appointmentPaymentValidity->isPastPaymentDeadline($appointment)) {
            return 'Pago bloqueado: cita vencida';
        }

        return 'Pago bloqueado';
    }

    private function checkoutDraft(Cart $cart): ?LaboratoryCheckoutDraft
    {
        $customer = $cart->user?->customer;
        if ($customer === null) {
            return null;
        }

        $brand = $this->flowResolver->resolve($cart)['brand'];
        if ($brand === null) {
            return null;
        }

        return LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();
    }

    private function itemsLabel(Cart $cart): string
    {
        $count = $cart->items->count();
        $noun = $count === 1 ? 'estudio' : 'estudios';

        return "{$count} {$noun}";
    }

    private function paymentAttemptCountLabel(int $count): ?string
    {
        if ($count <= 0) {
            return null;
        }

        return $count.' '.($count === 1 ? 'intento' : 'intentos');
    }

    /**
     * @param  array<string, mixed>|null  $paymentInsight
     * @return array<string, mixed>|null
     */
    private function journeyEligiblePaymentInsight(?array $paymentInsight): ?array
    {
        if ($paymentInsight === null) {
            return null;
        }

        if (($paymentInsight['confidence'] ?? null) !== 'explicit') {
            return null;
        }

        return $paymentInsight;
    }
}
