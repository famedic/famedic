<?php

namespace App\Support\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Http\Resources\Api\V1\LaboratoryAppointmentResource;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class LaboratoryAppointmentSupport
{
    public function __construct(
        private readonly CheckoutPreparation $checkoutPreparation,
    ) {}

    public function requiresAppointment(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $customer->getHasLaboratoryCartItemRequiringAppointment($brand);
    }

    public function hasValidAppointment(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand) !== null
            || $customer->getPendingLaboratoryAppointment($brand) !== null;
    }

    public function resolveAppointment(Customer $customer, LaboratoryBrand $brand): ?LaboratoryAppointment
    {
        return $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand)
            ?? $customer->getPendingLaboratoryAppointment($brand);
    }

    public function hasBlockingAppointment(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $this->hasValidAppointment($customer, $brand);
    }

    /**
     * @return Collection<int, \App\Models\LaboratoryCartItem>
     */
    public function requiredCartItems(Customer $customer, LaboratoryBrand $brand): Collection
    {
        return $this->checkoutPreparation->cartItems($customer, $brand)
            ->filter(fn ($item) => $item->laboratoryTest->requires_appointment)
            ->values();
    }

    public function resolveStatus(LaboratoryAppointment $appointment): string
    {
        if ($appointment->laboratory_purchase_id !== null) {
            return 'completed';
        }

        if ($appointment->confirmed_at !== null) {
            return 'confirmed';
        }

        return 'pending';
    }

    public function canContinueToPaymentLink(Customer $customer, LaboratoryBrand $brand): bool
    {
        if (! $this->requiresAppointment($customer, $brand)) {
            return true;
        }

        return $this->hasValidAppointment($customer, $brand);
    }

    public function requirements(Customer $customer, LaboratoryBrand $brand, Request $request): array
    {
        $items = $this->checkoutPreparation->cartItems($customer, $brand);
        $requiredItems = $this->requiredCartItems($customer, $brand);
        $requiresAppointment = $requiredItems->isNotEmpty();
        $appointment = $this->resolveAppointment($customer, $brand);
        $warnings = [];

        if ($items->isEmpty()) {
            $warnings[] = [
                'code' => 'EMPTY_CART',
                'message' => 'El carrito está vacío.',
            ];
        }

        if ($requiresAppointment && ! $appointment) {
            $warnings[] = [
                'code' => 'APPOINTMENT_REQUIRED',
                'message' => 'Este carrito requiere una cita antes de continuar al pago.',
            ];
        }

        return [
            'brand' => $brand->value,
            'requires_appointment' => $requiresAppointment,
            'has_appointment' => $appointment !== null,
            'can_continue_to_payment_link' => $items->isNotEmpty()
                && $this->canContinueToPaymentLink($customer, $brand),
            'appointment' => $appointment
                ? (new LaboratoryAppointmentResource($appointment))->resolve($request)
                : null,
            'required_items' => $requiredItems->map(fn ($item) => [
                'cart_item_id' => $item->id,
                'laboratory_test_id' => $item->laboratory_test_id,
                'name' => $item->laboratoryTest->name,
                'requires_appointment' => $item->laboratoryTest->requires_appointment,
            ])->all(),
            'warnings' => $warnings,
        ];
    }

    public function draftForBrand(Customer $customer, LaboratoryBrand $brand): ?LaboratoryCheckoutDraft
    {
        return LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();
    }
}
