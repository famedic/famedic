<?php

namespace App\Services;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCartMembership;
use Illuminate\Database\Eloquent\Collection;

class LaboratoryCartMembershipService
{
    public function priceCents(): int
    {
        return (int) config('famedic.medical_attention_subscription_price_cents', 30000);
    }

    public function formattedPrice(): string
    {
        return formattedCentsPrice($this->priceCents());
    }

    public function hasInCart(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $customer->laboratoryCartMemberships()
            ->ofBrand($brand)
            ->exists();
    }

    public function shouldShowCrossSell(
        Customer $customer,
        LaboratoryBrand $brand,
        Collection $laboratoryCartItems,
    ): bool {
        if ($customer->medical_attention_subscription_is_active) {
            return false;
        }

        if ($laboratoryCartItems->isEmpty()) {
            return false;
        }

        return ! $this->hasInCart($customer, $brand);
    }

    public function add(Customer $customer, LaboratoryBrand $brand): LaboratoryCartMembership
    {
        if ($customer->medical_attention_subscription_is_active) {
            throw new \InvalidArgumentException('Ya cuentas con una membresía médica activa.');
        }

        return $customer->laboratoryCartMemberships()->firstOrCreate([
            'laboratory_brand' => $brand,
        ]);
    }

    public function remove(Customer $customer, LaboratoryBrand $brand): void
    {
        $customer->laboratoryCartMemberships()
            ->ofBrand($brand)
            ->delete();
    }
}
