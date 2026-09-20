<?php

namespace App\Services\LaboratoryResults\AiExplanation;

use App\Enums\CustomerLaboratoryAiExplanationConsentStatus;
use App\Models\Customer;
use App\Models\CustomerLaboratoryAiExplanationConsent;
use App\Models\User;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;

final class LaboratoryResultAiExplanationConsentService
{
    public function statusForCustomer(Customer $customer): CustomerLaboratoryAiExplanationConsentStatus
    {
        $record = CustomerLaboratoryAiExplanationConsent::query()
            ->where('customer_id', $customer->id)
            ->first();

        return $record?->status ?? CustomerLaboratoryAiExplanationConsentStatus::NotRequested;
    }

    public function hasAcceptedConsent(Customer $customer): bool
    {
        if (! config('laboratory-results.ai_explanation.consent_required', true)) {
            return true;
        }

        return $this->statusForCustomer($customer)->allowsGeneration();
    }

    public function accept(Customer $customer, User $user): CustomerLaboratoryAiExplanationConsent
    {
        return CustomerLaboratoryAiExplanationConsent::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'user_id' => $user->id,
                'status' => CustomerLaboratoryAiExplanationConsentStatus::Accepted,
                'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
                'consented_at' => now(),
                'declined_at' => null,
            ],
        );
    }

    public function decline(Customer $customer, User $user): CustomerLaboratoryAiExplanationConsent
    {
        return CustomerLaboratoryAiExplanationConsent::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'user_id' => $user->id,
                'status' => CustomerLaboratoryAiExplanationConsentStatus::Declined,
                'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
                'consented_at' => null,
                'declined_at' => now(),
            ],
        );
    }
}
