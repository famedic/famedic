<?php

namespace App\Actions\Laboratories;

use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Services\Carts\CartOperationalEventService;

class AddItemToCartAction
{
    public function __construct(
        private CartOperationalEventService $cartOperationalEventService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(Customer $customer, int $laboratoryTestId, ?array $clientContext = null): LaboratoryCartItem
    {
        $laboratoryCartItem = $customer->laboratoryCartItems()
            ->whereLaboratoryTestId($laboratoryTestId)
            ->first();

        if ($laboratoryCartItem) {
            return $laboratoryCartItem;
        }

        $laboratoryCartItem = $customer->laboratoryCartItems()->save(new LaboratoryCartItem([
            'laboratory_test_id' => $laboratoryTestId,
        ]));

        $this->cartOperationalEventService->recordLaboratoryItemAdded(
            $laboratoryCartItem->load('laboratoryTest'),
            $clientContext,
        );

        return $laboratoryCartItem;
    }
}
