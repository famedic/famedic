<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Enums\MonitoringCartStatus;
use App\Models\Cart;
use App\Models\LaboratoryAppointment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EnrichLaboratoryAppointmentPendingRowAction
{
    public function __construct(
        private BuildLaboratoryAppointmentConciergeOperationalAgeAction $operationalAge,
        private BuildLaboratoryAppointmentCartActivitySignalAction $cartActivitySignal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        LaboratoryAppointment $appointment,
        ?Collection $resolvedCarts = null,
    ): array {
        $hasCartIdColumn = Schema::hasColumn('laboratory_appointments', 'cart_id');
        $resolvedCart = $this->resolveCartForRow($appointment, $resolvedCarts);
        $cartStatusLabel = $this->cartStatusLabel($hasCartIdColumn, $resolvedCart, $appointment);

        $lastActivityAt = $appointment->pending_last_user_activity_at ?? null;
        $priorityTier = isset($appointment->pending_priority_tier)
            ? (int) $appointment->pending_priority_tier
            : null;

        $parsedActivityAt = $lastActivityAt !== null
            ? Carbon::parse($lastActivityAt, 'UTC')->timezone('America/Monterrey')
            : null;

        return [
            'concierge_operational_age' => ($this->operationalAge)($appointment),
            'concierge_cart_activity_signal' => ($this->cartActivitySignal)($parsedActivityAt, $priorityTier),
            'admin_cart_status_label' => $cartStatusLabel,
            'admin_last_user_activity_human' => $parsedActivityAt?->format('d/m/Y H:i'),
            'pending_priority_tier' => $priorityTier,
        ];
    }

    private function resolveCartForRow(
        LaboratoryAppointment $appointment,
        ?Collection $resolvedCarts,
    ): ?Cart {
        if ($appointment->relationLoaded('cart') && $appointment->cart !== null) {
            return $appointment->cart;
        }

        $resolvedCartId = $appointment->pending_resolved_cart_id ?? null;

        if ($resolvedCartId === null) {
            return null;
        }

        if ($resolvedCarts !== null && $resolvedCarts->has($resolvedCartId)) {
            return $resolvedCarts->get($resolvedCartId);
        }

        return Cart::query()->find($resolvedCartId);
    }

    private function cartStatusLabel(
        bool $hasCartIdColumn,
        ?Cart $resolvedCart,
        LaboratoryAppointment $appointment,
    ): ?string {
        if ($resolvedCart !== null) {
            return match ($resolvedCart->status) {
                MonitoringCartStatus::Active => 'Carrito activo',
                MonitoringCartStatus::Completed => 'Carrito completado',
                default => 'Carrito '.$resolvedCart->status->value,
            };
        }

        if ($hasCartIdColumn && $appointment->cart_id === null) {
            return 'Sin carrito relacionado';
        }

        return null;
    }
}
