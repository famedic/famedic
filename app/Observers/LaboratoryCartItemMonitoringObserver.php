<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Services\Monitoring\SyncMonitoringCartService;

class LaboratoryCartItemMonitoringObserver
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {
    }

    public function saved(LaboratoryCartItem $laboratoryCartItem): void
    {
        $this->sync($laboratoryCartItem->customer_id);
    }

    public function deleted(LaboratoryCartItem $laboratoryCartItem): void
    {
        $this->sync($laboratoryCartItem->customer_id);
    }

    public function restored(LaboratoryCartItem $laboratoryCartItem): void
    {
        $this->sync($laboratoryCartItem->customer_id);
    }

    private function sync(?int $customerId): void
    {
        if (! $customerId) {
            return;
        }

        $customer = Customer::query()->find($customerId);
        if ($customer) {
            $this->syncMonitoringCartService->syncLaboratory($customer);
        }
    }
}
