<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\MedicalAttentionSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find free trial subscriptions (no transactions) that need extending
        $subscriptionsToCheck = MedicalAttentionSubscription::query()
            ->whereDoesntHave('transactions')
            ->where('price_cents', 0) // Extra safety: ensure these are free
            ->with(['customer', 'customer.familyMembers'])
            ->get();

        $updatedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($subscriptionsToCheck, &$updatedCount, &$skippedCount) {
            foreach ($subscriptionsToCheck as $subscription) {
                $startDate = Carbon::parse($subscription->start_date);
                $endDate = Carbon::parse($subscription->end_date);
                $daysDifference = $startDate->diffInDays($endDate);
                
                // Check if this looks like a 7-day trial (5-10 days to be safe)
                if ($daysDifference < 5 || $daysDifference > 10) {
                    $skippedCount++;
                    continue;
                }
                
                $originalEndDate = $subscription->end_date;
                $newEndDate = $startDate->copy()->addDays(30);
                
                // Skip if already extended
                if ($endDate->gte($newEndDate)) {
                    $skippedCount++;
                    continue;
                }

                // Update subscription end date
                $subscription->update(['end_date' => $newEndDate]);

                // Update customer's medical attention subscription expiration
                $customer = $subscription->customer;
                if (
                    !$customer->medical_attention_subscription_expires_at ||
                    $newEndDate->gt($customer->medical_attention_subscription_expires_at)
                ) {
                    $customer->update(['medical_attention_subscription_expires_at' => $newEndDate]);
                }

                // Update family members' medical attention subscription expiration
                $updatedFamilyMembers = [];
                foreach ($customer->familyMembers as $familyMember) {
                    $familyCustomer = $familyMember->customer;
                    if (
                        $familyCustomer &&
                        (!$familyCustomer->medical_attention_subscription_expires_at ||
                            $newEndDate->gt($familyCustomer->medical_attention_subscription_expires_at))
                    ) {
                        $familyCustomer->update(['medical_attention_subscription_expires_at' => $newEndDate]);
                        $updatedFamilyMembers[] = $familyCustomer->id;
                    }
                }

                $updatedCount++;

                $familyCount = count($updatedFamilyMembers);
                $familyText = $familyCount > 0 ? " + {$familyCount} family members" : "";
                
                Log::info("Trial extension: Customer {$subscription->customer_id}{$familyText} (subscription {$subscription->id})");
            }
        });

        echo "Migration completed. Updated {$updatedCount} subscriptions, skipped {$skippedCount}.\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration cannot be easily reversed as we don't want to 
        // reduce subscription periods that users are already benefiting from
        echo "This migration cannot be reversed.\n";
    }
};
