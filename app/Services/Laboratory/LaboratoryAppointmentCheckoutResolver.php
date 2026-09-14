<?php

namespace App\Services\Laboratory;

use App\Enums\LaboratoryBrand;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selección de citas para checkout web: prioriza cart_id activo, luego legacy sin cart_id.
 */
class LaboratoryAppointmentCheckoutResolver
{
    public function __construct(
        private LaboratoryAppointmentPaymentValidity $paymentValidity,
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {}

    public function activeCartId(Customer $customer, LaboratoryBrand $brand): ?int
    {
        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $brand);

        return $cart?->id;
    }

    public function payableConfirmedAppointment(
        Customer $customer,
        LaboratoryBrand $brand,
    ): ?LaboratoryAppointment {
        $cartId = $this->activeCartId($customer, $brand);

        return $this->selectPayableConfirmed(
            $this->confirmedUncompletedCandidates($customer, $brand),
            $cartId,
        );
    }

    public function activeAppointmentForCart(
        Customer $customer,
        LaboratoryBrand $brand,
    ): ?LaboratoryAppointment {
        $cartId = $this->activeCartId($customer, $brand);

        return $this->selectActive(
            $this->activeCandidates($customer, $brand),
            $cartId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function firstOrCreateActiveAppointmentForCart(
        Customer $customer,
        LaboratoryBrand $brand,
        ?array $clientContext = null,
    ): LaboratoryAppointment {
        $this->syncMonitoringCartService->syncLaboratory($customer, $clientContext);
        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $brand);

        if ($cart === null) {
            return $this->activeAppointmentForCart($customer, $brand)
                ?? $customer->laboratoryAppointments()->create([
                    'brand' => $brand,
                ]);
        }

        return DB::transaction(function () use ($customer, $brand, $cart) {
            Cart::query()
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->first();

            $appointment = $this->selectActive(
                $this->activeCandidates($customer, $brand),
                (int) $cart->id,
            );

            if ($appointment !== null) {
                return $this->ensureCartLink($appointment, (int) $cart->id);
            }

            return $customer->laboratoryAppointments()->create([
                'brand' => $brand,
                ...$this->cartAttributes((int) $cart->id),
            ]);
        });
    }

    public function pendingAppointment(
        Customer $customer,
        LaboratoryBrand $brand,
    ): ?LaboratoryAppointment {
        $cartId = $this->activeCartId($customer, $brand);

        return $this->selectPending(
            $customer->laboratoryAppointments()
                ->unconfirmed()
                ->whereNull('laboratory_purchase_id')
                ->ofBrand($brand)
                ->orderByDesc('id')
                ->get(),
            $cartId,
        );
    }

    public function isAwaitingConcierge(Customer $customer, LaboratoryBrand $brand): bool
    {
        $pending = $this->pendingAppointment($customer, $brand);

        return $pending !== null
            && ! $pending->trashed()
            && $pending->laboratory_purchase_id === null;
    }

    public function confirmedUnpaidAppointment(
        Customer $customer,
        LaboratoryBrand $brand,
    ): ?LaboratoryAppointment {
        $cartId = $this->activeCartId($customer, $brand);

        return $this->selectConfirmedUnpaid(
            $this->confirmedUncompletedCandidates($customer, $brand)
                ->reject(fn (LaboratoryAppointment $appointment) => $appointment->trashed()),
            $cartId,
        );
    }

    /**
     * @param  Collection<int, LaboratoryAppointment>  $candidates
     */
    private function selectPayableConfirmed(Collection $candidates, ?int $cartId): ?LaboratoryAppointment
    {
        $valid = $candidates->filter(
            fn (LaboratoryAppointment $appointment) => $this->paymentValidity->isValidForPayment($appointment, $cartId),
        );

        if ($cartId !== null) {
            $cartMatch = $valid->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->cart_id === (int) $cartId,
            );

            if ($cartMatch) {
                return $cartMatch;
            }

            $hasOtherCartMatch = $candidates->contains(
                fn (LaboratoryAppointment $appointment) => $appointment->cart_id !== null
                    && (int) $appointment->cart_id !== (int) $cartId,
            );

            if ($hasOtherCartMatch) {
                return null;
            }
        }

        return $valid
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->cart_id === null)
            ->sortByDesc(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at)
            ->first();
    }

    /**
     * @param  Collection<int, LaboratoryAppointment>  $candidates
     */
    private function selectActive(Collection $candidates, ?int $cartId): ?LaboratoryAppointment
    {
        $active = $candidates->reject(fn (LaboratoryAppointment $appointment) => $appointment->trashed());

        if ($cartId !== null) {
            $cartMatch = $active->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->cart_id === (int) $cartId,
            );

            if ($cartMatch) {
                return $cartMatch;
            }

            if ($active->contains(fn (LaboratoryAppointment $appointment) => $appointment->cart_id !== null)) {
                return null;
            }
        }

        return $active
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->cart_id === null)
            ->first();
    }

    /**
     * @param  Collection<int, LaboratoryAppointment>  $candidates
     */
    private function selectPending(Collection $candidates, ?int $cartId): ?LaboratoryAppointment
    {
        $active = $candidates->reject(fn (LaboratoryAppointment $appointment) => $appointment->trashed());

        if ($cartId !== null) {
            $cartMatch = $active->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->cart_id === (int) $cartId,
            );

            if ($cartMatch) {
                return $cartMatch;
            }

            if ($active->contains(fn (LaboratoryAppointment $appointment) => $appointment->cart_id !== null)) {
                return null;
            }
        }

        return $active->sortByDesc('id')->first();
    }

    /**
     * @param  Collection<int, LaboratoryAppointment>  $candidates
     */
    private function selectConfirmedUnpaid(Collection $candidates, ?int $cartId): ?LaboratoryAppointment
    {
        if ($cartId !== null) {
            $cartMatch = $candidates->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->cart_id === (int) $cartId,
            );

            if ($cartMatch) {
                return $cartMatch;
            }

            if ($candidates->contains(
                fn (LaboratoryAppointment $appointment) => $appointment->cart_id !== null
                    && (int) $appointment->cart_id !== (int) $cartId,
            )) {
                return null;
            }
        }

        return $candidates
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->cart_id === null)
            ->sortByDesc(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at)
            ->first();
    }

    /**
     * @return Collection<int, LaboratoryAppointment>
     */
    private function confirmedUncompletedCandidates(Customer $customer, LaboratoryBrand $brand): Collection
    {
        return $customer->laboratoryAppointments()
            ->whereNotNull('confirmed_at')
            ->whereNull('laboratory_purchase_id')
            ->ofBrand($brand)
            ->with('laboratoryStore')
            ->orderByDesc('confirmed_at')
            ->get();
    }

    /**
     * @return Collection<int, LaboratoryAppointment>
     */
    private function activeCandidates(Customer $customer, LaboratoryBrand $brand): Collection
    {
        return $customer->laboratoryAppointments()
            ->whereNull('laboratory_purchase_id')
            ->ofBrand($brand)
            ->with('laboratoryStore')
            ->orderByRaw('confirmed_at is null')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get();
    }

    private function ensureCartLink(LaboratoryAppointment $appointment, int $cartId): LaboratoryAppointment
    {
        if ($appointment->cart_id === null && Schema::hasColumn('laboratory_appointments', 'cart_id')) {
            $appointment->forceFill(['cart_id' => $cartId])->save();
        }

        return $appointment;
    }

    /**
     * @return array<string, int>
     */
    private function cartAttributes(int $cartId): array
    {
        if (! Schema::hasColumn('laboratory_appointments', 'cart_id')) {
            return [];
        }

        return ['cart_id' => $cartId];
    }
}
