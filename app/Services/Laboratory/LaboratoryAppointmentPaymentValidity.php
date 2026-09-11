<?php

namespace App\Services\Laboratory;

use App\Models\LaboratoryAppointment;
use Carbon\CarbonInterface;

/**
 * Regla única de vigencia para pagar con una cita confirmada de laboratorio.
 *
 * Zona funcional: America/Monterrey.
 */
class LaboratoryAppointmentPaymentValidity
{
    public const TIMEZONE = 'America/Monterrey';

    public function isValidForPayment(LaboratoryAppointment $appointment, ?int $expectedCartId = null): bool
    {
        if (! $this->isConfirmed($appointment)) {
            return false;
        }

        if ($appointment->trashed()) {
            return false;
        }

        if ($appointment->laboratory_purchase_id !== null) {
            return false;
        }

        if ($expectedCartId !== null
            && $appointment->cart_id !== null
            && (int) $appointment->cart_id !== (int) $expectedCartId) {
            return false;
        }

        if (! $this->hasScheduledDate($appointment)) {
            return false;
        }

        return ! $this->isPastPaymentDeadline($appointment);
    }

    public function isConfirmed(LaboratoryAppointment $appointment): bool
    {
        return $appointment->confirmed_at !== null;
    }

    public function hasScheduledDate(LaboratoryAppointment $appointment): bool
    {
        return $appointment->appointment_date !== null;
    }

    public function isPastPaymentDeadline(LaboratoryAppointment $appointment): bool
    {
        if ($appointment->appointment_date === null) {
            return true;
        }

        $scheduled = localizedDate($appointment->appointment_date);
        if (! $scheduled instanceof CarbonInterface) {
            return false;
        }

        $now = now(self::TIMEZONE);

        if ($this->treatsAsDateOnly($scheduled)) {
            return $now->gt($scheduled->copy()->endOfDay());
        }

        return $now->gt($scheduled);
    }

    public function paymentDeadlineMessage(): string
    {
        return 'La fecha programada de tu cita ya pasó. Solicita una nueva cita para continuar con el pago.';
    }

    public function paymentBlockedBeforeConfirmationMessage(): string
    {
        return 'Primero debes confirmar tu cita de laboratorio. Cuando nuestro equipo la confirme, podrás seleccionar tu método de pago.';
    }

    public function paymentBlockedUnavailableMessage(): string
    {
        return 'Tu cita ya no está disponible para completar el pago. Puedes solicitar una nueva cita desde este paso.';
    }

    public function paymentBlockedMissingScheduleMessage(): string
    {
        return 'Tu cita está confirmada pero aún no tiene una fecha programada. Nuestro equipo debe asignarla antes de que puedas continuar con el pago.';
    }

    private function treatsAsDateOnly(CarbonInterface $scheduled): bool
    {
        return $scheduled->format('H:i:s') === '00:00:00';
    }
}
