<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Monitoring\SyncMonitoringCartService;

class OnlinePharmacyCartItemMonitoringObserver
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {
    }

    public function saved(OnlinePharmacyCartItem $onlinePharmacyCartItem): void
    {
        $this->sync($onlinePharmacyCartItem->customer_id);
    }

    public function deleted(OnlinePharmacyCartItem $onlinePharmacyCartItem): void
    {
        $this->sync($onlinePharmacyCartItem->customer_id);
    }

    public function restored(OnlinePharmacyCartItem $onlinePharmacyCartItem): void
    {
        $this->sync($onlinePharmacyCartItem->customer_id);
    }

    private function sync(?int $customerId): void
    {
        if (! $customerId) {
            return;
        }

        $customer = Customer::query()->find($customerId);
        if ($customer) {
            $this->syncMonitoringCartService->syncPharmacy($customer);
        }
    }
}
