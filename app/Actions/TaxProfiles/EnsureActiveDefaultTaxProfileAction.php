<?php

namespace App\Actions\TaxProfiles;

use App\Models\Customer;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza exactamente 0 o 1 predeterminado activo por customer.
 * Debe invocarse dentro de una transacción con el customer ya bloqueado (lockForUpdate).
 */
class EnsureActiveDefaultTaxProfileAction
{
    public function __invoke(Customer $customer, ?TaxProfile $preferredActive = null): ?TaxProfile
    {
        // Perfiles inactivos no son predeterminados operativos.
        TaxProfile::withTrashed()
            ->where('customer_id', $customer->id)
            ->whereNotNull('deleted_at')
            ->where('is_default', true)
            ->orderBy('id')
            ->each(function (TaxProfile $profile): void {
                $profile->forceFill(['is_default' => false])->save();
            });

        $active = TaxProfile::query()
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($active->isEmpty()) {
            TaxProfile::withTrashed()
                ->where('customer_id', $customer->id)
                ->where('is_default', true)
                ->orderBy('id')
                ->each(function (TaxProfile $profile): void {
                    $profile->forceFill(['is_default' => false])->save();
                });

            return null;
        }

        $keep = null;

        if (
            $preferredActive
            && $preferredActive->customer_id === $customer->id
            && $preferredActive->deleted_at === null
            && $active->contains(fn (TaxProfile $profile) => $profile->id === $preferredActive->id)
        ) {
            $keep = $active->firstWhere('id', $preferredActive->id);
        }

        if ($keep === null) {
            $existingDefaults = $active->where('is_default', true)->values();

            if ($existingDefaults->count() === 1) {
                $keep = $existingDefaults->first();
            } elseif ($existingDefaults->count() > 1) {
                $keep = $existingDefaults
                    ->sortBy([
                        ['created_at', 'desc'],
                        ['id', 'desc'],
                    ])
                    ->first();
            } else {
                $keep = $active->first(); // ya ordenado created_at DESC, id DESC
            }
        }

        foreach ($active as $profile) {
            $shouldBeDefault = $profile->id === $keep->id;

            if ((bool) $profile->is_default !== $shouldBeDefault) {
                $profile->forceFill(['is_default' => $shouldBeDefault])->save();
            }
        }

        return $keep->fresh();
    }

    /**
     * Bloquea el customer y repara el predeterminado activo.
     */
    public function forCustomerId(int $customerId, ?TaxProfile $preferredActive = null): ?TaxProfile
    {
        return DB::transaction(function () use ($customerId, $preferredActive) {
            $customer = Customer::query()
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            return ($this)($customer, $preferredActive);
        });
    }
}
