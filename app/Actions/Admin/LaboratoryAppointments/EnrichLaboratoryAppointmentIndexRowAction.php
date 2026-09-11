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

        $lastActivityHuman = null;
        if ($appointment->cart_id && $appointment->relationLoaded('cart') && $appointment->cart !== null) {
            $lastActivityHuman = $this->activityResolver
                ->lastUserActivityAt($appointment->cart)
                ->timezone('America/Monterrey')
                ->format('d/m/Y H:i');
        }

        return [
            'admin_checkout_flow' => $progress['checkout_flow'],
            'admin_payment_status_label' => $payable ? 'Pago disponible' : 'Pago bloqueado',
            'admin_payment_blocked' => ! $payable,
            'admin_payment_blocked_reason' => $progress['payment_blocked_reason'],
            'admin_last_user_activity_human' => $lastActivityHuman,
        ];
    }
}
