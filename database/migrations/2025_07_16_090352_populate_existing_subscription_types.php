<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Starting subscription type population...');

        $allSubscriptions = MedicalAttentionSubscription::whereNull('type')
            ->with('customer.familyAccounts.customer', 'transactions')
            ->orderBy('id')
            ->get();

        if ($allSubscriptions->isEmpty()) {
            Log::info('No subscriptions to process');
            return;
        }

        Log::info('Processing subscriptions', ['count' => $allSubscriptions->count()]);

        $typeCounts = ['trial' => 0, 'regular' => 0, 'institutional' => 0];
        $familyCreated = 0;
        $skipped = 0;

        foreach ($allSubscriptions as $subscription) {
            $customer = $subscription->customer;
            if (!$customer) {
                $skipped++;
                continue;
            }

            $type = $this->determineSubscriptionType($customer, $subscription);
            $subscription->update(['type' => $type]);
            $typeCounts[strtolower(explode('_', $type)[0])]++;

            if (in_array($type, [
                MedicalSubscriptionType::TRIAL->value,
                MedicalSubscriptionType::REGULAR->value,
                MedicalSubscriptionType::INSTITUTIONAL->value
            ])) {
                $familyCreated += $this->createFamilyMemberSubscriptionsForParent($customer, $subscription);
            }
        }

        Log::info('Subscription type population completed', [
            'processed' => $allSubscriptions->count() - $skipped,
            'skipped' => $skipped,
            'types' => $typeCounts,
            'family_created' => $familyCreated,
        ]);
    }

    private function determineSubscriptionType($customer, $subscription): string
    {
        if ($subscription->price_cents == 0) {
            $startDate = new DateTime($subscription->start_date);
            $endDate = new DateTime($subscription->end_date);
            $duration = $startDate->diff($endDate)->days;
            $trialPeriod = config('famedic.free_medical_attention_subscription_days', 30);

            if ($duration <= ($trialPeriod + 2)) {
                return MedicalSubscriptionType::TRIAL->value;
            }
        }

        if ($subscription->transactions->count() > 0) {
            return MedicalSubscriptionType::REGULAR->value;
        }

        return MedicalSubscriptionType::INSTITUTIONAL->value;
    }

    private function createFamilyMemberSubscriptionsForParent($customer, $subscription): int
    {
        $familySubscriptionsToCreate = [];
        $familyCustomerIds = [];

        foreach ($customer->familyAccounts as $familyAccount) {
            $familyCustomer = $familyAccount->customer;
            $familySubscriptionsToCreate[] = [
                'customer_id' => $familyCustomer->id,
                'parent_subscription_id' => $subscription->id,
                'start_date' => $subscription->start_date,
                'end_date' => $subscription->end_date,
                'price_cents' => 0,
                'type' => MedicalSubscriptionType::FAMILY_MEMBER->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $familyCustomerIds[] = $familyCustomer->id;
        }

        if (!empty($familySubscriptionsToCreate)) {
            MedicalAttentionSubscription::insert($familySubscriptionsToCreate);
            Customer::whereIn('id', $familyCustomerIds)
                ->update(['medical_attention_subscription_expires_at' => $customer->medical_attention_subscription_expires_at]);
        }

        return count($familySubscriptionsToCreate);
    }
};
