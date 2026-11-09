<?php

namespace App\Services\MedicalCheckout;

use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Models\Customer;
use App\Models\MedicalServiceCart;

final class MedicalCheckoutSelectionGuard
{
    public function assertNoIgnoredBenefit(Customer $customer): void
    {
        if (MedicalServiceCart::query()->where('customer_id', $customer->id)->where('status', 'active')->whereHas('benefits')->exists()) {
            throw new BenefitFailure('MEDICAL_BENEFIT_CHECKOUT_REQUIRES_SETTLEMENT');
        }
    }
}
