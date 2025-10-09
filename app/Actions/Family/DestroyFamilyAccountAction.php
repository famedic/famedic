<?php

namespace App\Actions\Family;

use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\FamilyAccount;
use Illuminate\Support\Facades\DB;

class DestroyFamilyAccountAction
{
    public function __invoke(
        FamilyAccount $familyAccount
    ): void {
        DB::beginTransaction();

        try {
            $customer = $familyAccount->customer;

            if ($customer->medicalAttentionSubscriptions()->exists()) {
                $medicalAttentionSubscription = $customer->medicalAttentionSubscriptions()->latest()->first();

                SyncSubscriptionToMurguiaJob::dispatch(
                    $medicalAttentionSubscription,
                    'inactivo',
                    $medicalAttentionSubscription->start_date,
                    $medicalAttentionSubscription->end_date
                );
            }

            $customer->delete();
            $familyAccount->delete();

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }
}
