<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Enums\CartCheckoutFlowType;
use App\Models\Cart;
use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Services\Carts\CartAdminStageInterpreter;
use App\Services\Carts\CartCheckoutFlowResolver;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;

class BuildLaboratoryAppointmentCheckoutProgressAction
{
    public function __construct(
        private CartCheckoutFlowResolver $flowResolver,
        private CartAdminStageInterpreter $stageInterpreter,
        private LaboratoryAppointmentPaymentValidity $appointmentPaymentValidity,
    ) {}

    /**
     * @return array{
     *     steps: list<array{id: string, label: string, status: string, detail: string|null}>,
     *     draft_updated_at: string|null,
     *     checkout_flow: array{value: string, label: string, confidence: string}|null,
     *     payment_blocked_reason: string|null,
     *     resume_step: string|null,
     * }
     */
    public function __invoke(LaboratoryAppointment $appointment): array
    {
        $appointment->loadMissing([
            'customer',
            'laboratoryPurchase.transactions',
        ]);

        $cart = $appointment->cart_id
            ? Cart::query()->with(['items', 'events', 'laboratoryAppointments', 'laboratoryPurchases'])->find($appointment->cart_id)
            : null;

        if ($cart !== null) {
            $flowMeta = $this->flowResolver->resolve($cart, $appointment->brand);
            $payable = $this->stageInterpreter->appointmentPayableState($appointment, (int) $cart->id);

            return [
                'steps' => $this->mapJourneyToSteps(
                    $this->stageInterpreter->journey(
                        $cart,
                        [],
                        $appointment,
                        $cart->explicitLaboratoryPurchase(),
                        null,
                        null,
                    ),
                ),
                'draft_updated_at' => $this->draftUpdatedAt($appointment),
                'checkout_flow' => [
                    'value' => $flowMeta['flow']->value,
                    'label' => $flowMeta['label'],
                    'confidence' => $flowMeta['confidence'],
                ],
                'payment_blocked_reason' => $payable['payable'] ? null : $payable['reason'],
                'resume_step' => $flowMeta['flow'] === CartCheckoutFlowType::AppointmentFirst ? 'payment' : 'confirmation',
            ];
        }

        return array_merge($this->legacyProgress($appointment), [
            'checkout_flow' => null,
            'payment_blocked_reason' => $this->legacyPaymentBlockedReason($appointment),
            'resume_step' => 'confirmation',
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, state: string, detail: string}>  $journey
     * @return list<array{id: string, label: string, status: string, detail: string|null}>
     */
    private function mapJourneyToSteps(array $journey): array
    {
        return collect($journey)
            ->reject(fn (array $step) => $step['key'] === 'items')
            ->map(fn (array $step) => [
                'id' => $step['key'] === 'purchase' ? 'purchase' : $step['key'],
                'label' => match ($step['key']) {
                    'purchase' => 'Status de pago',
                    'payment' => 'Método de pago',
                    'appointment' => 'Status de cita',
                    default => $step['label'],
                },
                'status' => $step['state'],
                'detail' => $step['detail'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{steps: list<array{id: string, label: string, status: string, detail: string|null}>, draft_updated_at: string|null}
     */
    private function legacyProgress(LaboratoryAppointment $appointment): array
    {
        $purchase = $appointment->laboratoryPurchase;

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $appointment->customer_id)
            ->where('laboratory_brand', $appointment->brand)
            ->with(['contact', 'address'])
            ->first();

        $checkoutStep = $draft?->checkout_step ?? 'patient';
        $hasPatient = filled($appointment->patient_name) || ($draft?->contact_id !== null);
        $hasAddress = $draft?->address_id !== null || $purchase !== null;
        $hasPaymentMethod = filled($draft?->payment_method)
            || ($purchase !== null && $purchase->transactions->isNotEmpty());
        $isAppointmentConfirmed = $appointment->confirmed_at !== null;
        $isPaid = $appointment->hasPaidLaboratoryPurchase();

        $steps = [
            $this->step('patient', 'Paciente', $hasPatient, $checkoutStep === 'patient' && ! $hasPatient, $hasPatient ? $appointment->patient_full_name : null),
            $this->step('address', 'Dirección', $hasAddress, $checkoutStep === 'address' && ! $hasAddress, $this->shortAddressLabel($draft?->address)),
            $this->step('payment', 'Método de pago', $hasPaymentMethod, $checkoutStep === 'payment' && ! $hasPaymentMethod, $this->paymentMethodLabel($draft?->payment_method, $appointment->customer)),
            $this->step('appointment', 'Status de cita', $isAppointmentConfirmed, ! $isAppointmentConfirmed, $isAppointmentConfirmed ? 'Confirmada' : 'Esperando confirmación del concierge'),
            $this->step('purchase', 'Status de pago', $isPaid, ! $isPaid && $checkoutStep === 'confirmation', $isPaid ? 'Compra pagada' : 'Sin pago registrado'),
        ];

        return [
            'steps' => $steps,
            'draft_updated_at' => $draft?->updated_at?->timezone('America/Monterrey')?->format('d/m/Y H:i'),
        ];
    }

    private function legacyPaymentBlockedReason(LaboratoryAppointment $appointment): ?string
    {
        if ($this->appointmentPaymentValidity->isValidForPayment($appointment)) {
            return null;
        }

        if (! $this->appointmentPaymentValidity->isConfirmed($appointment)) {
            return 'Cita sin confirmar';
        }

        if ($this->appointmentPaymentValidity->isPastPaymentDeadline($appointment)) {
            return 'Cita vencida';
        }

        return 'Pago no disponible';
    }

    private function draftUpdatedAt(LaboratoryAppointment $appointment): ?string
    {
        return LaboratoryCheckoutDraft::query()
            ->where('customer_id', $appointment->customer_id)
            ->where('laboratory_brand', $appointment->brand)
            ->value('updated_at')
            ?->timezone('America/Monterrey')
            ?->format('d/m/Y H:i');
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string|null}
     */
    private function step(string $id, string $label, bool $completed, bool $current, ?string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => match (true) {
                $completed => 'completed',
                $current => 'current',
                default => 'pending',
            },
            'detail' => $detail,
        ];
    }

    private function shortAddressLabel(?\App\Models\Address $address): ?string
    {
        if (! $address) {
            return null;
        }

        $text = trim((string) ($address->formatted_address ?: $address->full_address));

        return $text === '' ? null : (mb_strlen($text) > 56 ? mb_substr($text, 0, 53).'…' : $text);
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
            default => ctype_digit($paymentMethod)
                ? $this->efevooTokenPaymentLabel($paymentMethod, $customer)
                : $paymentMethod,
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

        return $token
            ? sprintf('%s •••• %s', ucfirst(strtolower((string) $token->card_brand)), $token->card_last_four)
            : 'Tarjeta #'.$paymentMethod;
    }
}
