<?php

namespace App\Actions\Api\V1;

use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Actions\Laboratories\SyncLaboratoryCheckoutDraftAction;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;

class ClearAkubicaCartAction
{
    public function __construct(
        private readonly DeleteItemFromCartAction $deleteItemFromCartAction,
        private readonly SyncLaboratoryCheckoutDraftAction $syncLaboratoryCheckoutDraftAction,
    ) {}

    public function __invoke(Customer $customer, LaboratoryBrand $brand): int
    {
        $items = $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->get();

        $deletedCount = $items->count();

        foreach ($items as $item) {
            ($this->deleteItemFromCartAction)($item);
        }

        $this->syncLaboratoryCheckoutDraftAction->clearForCustomer($customer, $brand);

        return $deletedCount;
    }
}
