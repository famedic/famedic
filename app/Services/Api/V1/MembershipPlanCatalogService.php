<?php

namespace App\Services\Api\V1;

use App\Enums\MedicalSubscriptionType;
use App\Models\Customer;
use Carbon\CarbonInterface;

class MembershipPlanCatalogService
{
    public const BASIC_ANNUAL = 'basic_annual';

    public function plans(): array
    {
        return [$this->basicPlan()];
    }

    public function status(Customer $customer, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $activeSubscription = $customer->medicalAttentionSubscriptions()
            ->active($now)
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if ($activeSubscription) {
            $subscriptionType = $this->canonicalType($activeSubscription->getRawOriginal('type'));
            $sponsored = in_array($subscriptionType, [
                MedicalSubscriptionType::INSTITUTIONAL,
                MedicalSubscriptionType::FAMILY_MEMBER,
            ], true);

            return $this->subscriptionStatus(
                $activeSubscription,
                'active',
                false,
                $sponsored ? 'sponsored_membership_active' : 'membership_already_active',
                $sponsored ? 'sponsored' : 'individual',
                $sponsored ? null : $this->basicPlan(),
            );
        }

        $latestExpired = $customer->medicalAttentionSubscriptions()
            ->where('end_date', '<', $now)
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if ($latestExpired) {
            return $this->subscriptionStatus(
                $latestExpired,
                'expired',
                true,
                null,
                $this->sourceFor($latestExpired->getRawOriginal('type')),
                $this->canonicalType($latestExpired->getRawOriginal('type')) === MedicalSubscriptionType::REGULAR ? $this->basicPlan() : null,
            );
        }

        return [
            'status' => 'none',
            'plan_code' => null,
            'plan_name' => null,
            'source' => null,
            'starts_at' => null,
            'expires_at' => null,
            'purchase_eligible' => true,
            'ineligibility_reason' => null,
        ];
    }

    private function basicPlan(): array
    {
        return [
            'code' => self::BASIC_ANNUAL,
            'name' => 'Plan Básico',
            'audience' => 'individual',
            'price_cents' => (int) config('famedic.medical_attention_subscription_price_cents', 30000),
            'currency' => 'MXN',
            'tax_included' => true,
            'billing_type' => 'one_time',
            'validity_months' => 12,
            'trial_days' => 0,
            'is_available' => true,
        ];
    }

    private function subscriptionStatus(
        object $subscription,
        string $status,
        bool $purchaseEligible,
        ?string $reason,
        string $source,
        ?array $plan,
    ): array {
        return [
            'status' => $status,
            'plan_code' => $plan['code'] ?? null,
            'plan_name' => $plan['name'] ?? null,
            'source' => $source,
            'starts_at' => $subscription->start_date?->toDateString(),
            'expires_at' => $subscription->end_date?->toDateString(),
            'purchase_eligible' => $purchaseEligible,
            'ineligibility_reason' => $reason,
        ];
    }

    private function sourceFor(MedicalSubscriptionType|string|null $type): string
    {
        return in_array($this->canonicalType($type), [
            MedicalSubscriptionType::INSTITUTIONAL,
            MedicalSubscriptionType::FAMILY_MEMBER,
        ], true) ? 'sponsored' : 'individual';
    }

    private function canonicalType(MedicalSubscriptionType|string|null $type): ?MedicalSubscriptionType
    {
        if ($type instanceof MedicalSubscriptionType) {
            return $type;
        }

        return $type === null ? null : MedicalSubscriptionType::tryFrom((string) $type);
    }
}
