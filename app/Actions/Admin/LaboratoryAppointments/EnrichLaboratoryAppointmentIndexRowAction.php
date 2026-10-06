<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;
use App\Services\Carts\CartUserActivityResolver;

class EnrichLaboratoryAppointmentIndexRowAction
{
    public function __construct(
        private BuildLaboratoryAppointmentCheckoutProgressAction $checkoutProgress,
        private CartUserActivityResolver $activityResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(LaboratoryAppointment $appointment): array
    {
        $progress = ($this->checkoutProgress)($appointment);
        $payable = $progress['payment_blocked_reason'] === null;
        $paid = $appointment->hasPaidLaboratoryPurchase();

        $lastActivityHuman = null;
        if ($appointment->cart_id && $appointment->relationLoaded('cart') && $appointment->cart !== null) {
            $lastActivityAt = $this->activityResolver
                ->lastUserActivityAt($appointment->cart)
                ->timezone('America/Monterrey')
                ->locale('es');

            $lastActivityHuman = sprintf(
                '%s/%s/%s %s',
                $lastActivityAt->format('d'),
                str_replace('.', '', mb_strtolower($lastActivityAt->isoFormat('MMM'))),
                $lastActivityAt->format('Y'),
                $lastActivityAt->format('h:i A'),
            );
        }

        return [
            'admin_checkout_flow' => $progress['checkout_flow'],
            'admin_payment_status_label' => $paid ? 'Pago confirmado' : ($payable ? 'Pago disponible' : 'Pago bloqueado'),
            'admin_is_paid' => $paid,
            'admin_payment_blocked' => ! $payable,
            'admin_payment_blocked_reason' => $progress['payment_blocked_reason'],
            'admin_last_user_activity_human' => $lastActivityHuman,
        ];
    }
}
