<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\Cart;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Carts\CartOperationalEventService;

class DeleteItemFromCartAction
{
    public function __construct(
        private CartOperationalEventService $cartOperationalEventService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(OnlinePharmacyCartItem $onlinePharmacyCartItem, ?array $clientContext = null): void
    {
        $onlinePharmacyCartItem->loadMissing('customer');

        $customer = $onlinePharmacyCartItem->customer;
        $itemId = $onlinePharmacyCartItem->id;
        $wasLastItem = $customer->onlinePharmacyCartItems()->count() === 1;
        $cart = $this->cartOperationalEventService->resolvePharmacyCart($customer);
        $snapshot = $this->cartOperationalEventService->snapshotPharmacyItem($onlinePharmacyCartItem);

        $onlinePharmacyCartItem->delete();

        $this->cartOperationalEventService->recordPharmacyItemRemoved($snapshot, $cart, $clientContext);

        if ($wasLastItem && $cart instanceof Cart) {
            $this->cartOperationalEventService->recordCartEmptied(
                $cart,
                $clientContext,
                "cart:{$cart->id}:emptied:via:online_pharmacy_cart_item:{$itemId}:deleted",
                $snapshot,
            );
        }
    }
}
