<?php

namespace App\Actions\Laboratories;

use App\Models\Cart;
use App\Models\LaboratoryCartItem;
use App\Services\Carts\CartOperationalEventService;

class DeleteItemFromCartAction
{
    public function __construct(
        private CartOperationalEventService $cartOperationalEventService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(LaboratoryCartItem $laboratoryCartItem, ?array $clientContext = null): void
    {
        $laboratoryCartItem->loadMissing('laboratoryTest', 'customer');

        $customer = $laboratoryCartItem->customer;
        $brand = $laboratoryCartItem->laboratoryTest?->brand;
        $itemId = $laboratoryCartItem->id;
        $wasLastForBrand = $brand !== null
            && $customer->laboratoryCartItems()->ofBrand($brand)->count() === 1;
        $cart = $this->cartOperationalEventService->resolveLaboratoryCart($customer, $brand);
        $snapshot = $this->cartOperationalEventService->snapshotLaboratoryItem($laboratoryCartItem);

        $laboratoryCartItem->delete();

        $this->cartOperationalEventService->recordLaboratoryItemRemoved($snapshot, $cart, $clientContext);

        if ($wasLastForBrand && $cart instanceof Cart) {
            $this->cartOperationalEventService->recordCartEmptied(
                $cart,
                $clientContext,
                "cart:{$cart->id}:emptied:via:laboratory_cart_item:{$itemId}:deleted",
                $snapshot,
            );
        }
    }
}
