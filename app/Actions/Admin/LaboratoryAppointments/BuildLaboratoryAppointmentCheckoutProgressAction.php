<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;

class BuildLaboratoryAppointmentCheckoutProgressAction
{
    /**
     * @return array{
     *     steps: list<array{
     *         id: string,
     *         label: string,
     *         status: string,
     *         detail: string|null,
     *     }>,
     *     draft_updated_at: string|null,
     * }
     */
    public function __invoke(LaboratoryAppointment $appointment): array
    {
        $appointment->loadMissing(['customer']);

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $appointment->customer_id)
            ->where('laboratory_brand', $appointment->brand)
            ->with(['contact', 'address'])
            ->first();

        $checkoutStep = $draft?->checkout_step ?? 'patient';

        $appointmentPatientName = $appointment->patient_full_name;
        $hasAppointmentPatient = filled($appointment->patient_name);

        $hasPatient = $hasAppointmentPatient || ($draft?->contact_id !== null);
        $hasAddress = $draft?->address_id !== null;
        $hasPaymentMethod = filled($draft?->payment_method);
        $isAppointmentConfirmed = $appointment->confirmed_at !== null;
        $hasAppointmentActivity = $isAppointmentConfirmed
            || (bool) $appointment->has_left_callback_info
            || $appointment->phone_call_intent_at !== null
            || $hasAppointmentPatient;
        $isPaid = $appointment->hasPaidLaboratoryPurchase();

        // En detalle de cita, el paciente de la solicitud tiene prioridad sobre el borrador
        // (el draft puede quedar con otro contact_id si el usuario cambió de paciente en checkout).
        $patientDetail = $hasAppointmentPatient
            ? $appointmentPatientName
            : $draft?->contact?->full_name;

        $addressDetail = $this->shortAddressLabel($draft?->address);

        $paymentDetail = $this->paymentMethodLabel(
            $draft?->payment_method,
            $appointment->customer,
        );

        $appointmentDetail = match (true) {
            $isAppointmentConfirmed => 'Confirmada'
                .($appointment->formatted_appointment_date
                    ? ' · '.$appointment->formatted_appointment_date
                    : ''),
            (bool) $appointment->has_left_callback_info => 'Solicitud con disponibilidad registrada',
            $appointment->phone_call_intent_at !== null => 'Intentó llamar',
            $hasPatient => 'Solicitud pendiente de confirmación',
            default => null,
        };

        $paymentStatusDetail = $isPaid ? 'Compra pagada' : 'Sin pago registrado';

        $steps = [
            $this->step(
                id: 'patient',
                label: 'Paciente',
                completed: $hasPatient,
                current: $checkoutStep === 'patient' && ! $hasPatient,
                detail: $hasPatient ? $patientDetail : null,
            ),
            $this->step(
                id: 'address',
                label: 'Dirección',
                completed: $hasAddress,
                current: $checkoutStep === 'address' && ! $hasAddress,
                detail: $hasAddress ? $addressDetail : null,
            ),
            $this->step(
                id: 'payment',
                label: 'Método de pago',
                completed: $hasPaymentMethod,
                current: $checkoutStep === 'payment' && ! $hasPaymentMethod,
                detail: $hasPaymentMethod ? $paymentDetail : null,
            ),
            $this->step(
                id: 'appointment',
                label: 'Status de cita',
                completed: $isAppointmentConfirmed,
                current: ! $isAppointmentConfirmed && (
                    $checkoutStep === 'appointment'
                    || $checkoutStep === 'confirmation'
                    || $hasAppointmentActivity
                ),
                detail: $appointmentDetail,
            ),
            $this->step(
                id: 'purchase',
                label: 'Status de pago',
                completed: $isPaid,
                current: ! $isPaid && $checkoutStep === 'confirmation',
                detail: $paymentStatusDetail,
            ),
        ];

        return [
            'steps' => $steps,
            'draft_updated_at' => $draft?->updated_at
                ?->timezone('America/Monterrey')
                ?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string|null}
     */
    private function step(
        string $id,
        string $label,
        bool $completed,
        bool $current,
        ?string $detail,
    ): array {
        $status = match (true) {
            $completed => 'completed',
            $current => 'current',
            default => 'pending',
        };

        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
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

        return mb_strlen($text) > 56
            ? mb_substr($text, 0, 53).'…'
            : $text;
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
