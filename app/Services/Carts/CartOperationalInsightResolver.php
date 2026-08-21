<?php

namespace App\Services\Carts;

use App\Enums\MonitoringCartStatus;
use App\Models\Cart;
use App\Models\PaymentAttempt;

class CartOperationalInsightResolver
{
    /**
     * @return array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}
     */
    public function resolve(Cart $cart, ?array $paymentInsight = null): array
    {
        if ($cart->status === MonitoringCartStatus::Completed) {
            return $this->none();
        }

        $paymentReason = $this->paymentReason($paymentInsight);
        if ($paymentReason !== null) {
            return $paymentReason;
        }

        $appointment = $cart->laboratoryAppointmentsForDisplay()->first();

        if ($appointment?->has_left_callback_info) {
            return [
                'requires_attention' => true,
                'category' => 'contact',
                'reason' => 'callback_requested',
                'label' => 'Solicito llamada',
                'recommended_action' => 'Llamar al paciente',
                'tone' => 'violet',
            ];
        }

        if ($appointment?->confirmed_at !== null && $appointment->laboratory_purchase_id === null) {
            return [
                'requires_attention' => true,
                'category' => 'appointment',
                'reason' => 'appointment_confirmed_without_payment',
                'label' => 'Cita confirmada sin pago',
                'recommended_action' => 'Recordar completar el pago',
                'tone' => 'violet',
            ];
        }

        if ($appointment && $appointment->confirmed_at === null) {
            return [
                'requires_attention' => true,
                'category' => 'appointment',
                'reason' => 'appointment_pending',
                'label' => 'Cita pendiente',
                'recommended_action' => 'Dar seguimiento a la confirmacion',
                'tone' => 'amber',
            ];
        }

        if ($appointment?->phone_call_intent_at !== null) {
            return [
                'requires_attention' => true,
                'category' => 'contact',
                'reason' => 'phone_call_intent',
                'label' => 'Intento llamar',
                'recommended_action' => 'Dar seguimiento al contacto',
                'tone' => 'sky',
            ];
        }

        if ($cart->displayStatus() === 'abandoned') {
            return [
                'requires_attention' => false,
                'category' => 'abandoned',
                'reason' => 'abandoned',
                'label' => 'Carrito abandonado',
                'recommended_action' => 'Sin accion inmediata',
                'tone' => 'zinc',
            ];
        }

        return $this->none();
    }

    /**
     * @return array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}|null
     */
    private function paymentReason(?array $paymentInsight): ?array
    {
        if (($paymentInsight['should_display'] ?? false) !== true) {
            return null;
        }

        return match ($paymentInsight['status'] ?? null) {
            PaymentAttempt::STATUS_ERROR => [
                'requires_attention' => true,
                'category' => 'payment',
                'reason' => 'payment_error',
                'label' => 'Error tecnico de pago',
                'recommended_action' => 'Revisar incidente de pago',
                'tone' => 'red',
            ],
            PaymentAttempt::STATUS_DECLINED => [
                'requires_attention' => true,
                'category' => 'payment',
                'reason' => 'payment_declined',
                'label' => 'Pago rechazado',
                'recommended_action' => 'Contactar para apoyar con el pago',
                'tone' => 'red',
            ],
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => [
                'requires_attention' => true,
                'category' => 'payment',
                'reason' => 'payment_pending',
                'label' => 'Pago pendiente',
                'recommended_action' => 'Revisar estado antes de contactar',
                'tone' => 'amber',
            ],
            default => null,
        };
    }

    /**
     * @return array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}
     */
    private function none(): array
    {
        return [
            'requires_attention' => false,
            'category' => 'none',
            'reason' => 'none',
            'label' => 'Sin atencion requerida',
            'recommended_action' => 'Sin accion inmediata',
            'tone' => 'zinc',
        ];
    }
}
