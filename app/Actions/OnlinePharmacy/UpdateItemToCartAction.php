<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\Cart;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Carts\CartOperationalEventService;

class UpdateItemToCartAction
{
    public function __construct(
        private CartOperationalEventService $cartOperationalEventService,
        private DeleteItemFromCartAction $deleteItemFromCartAction,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(
        OnlinePharmacyCartItem $onlinePharmacyCartItem,
        int $quantity,
        ?array $clientContext = null,
    ): OnlinePharmacyCartItem|null {
        $onlinePharmacyCartItem->loadMissing('customer');

        if ($quantity <= 0) {
            ($this->deleteItemFromCartAction)($onlinePharmacyCartItem, $clientContext);

            return null;
        }

        $previousQuantity = max(1, (int) $onlinePharmacyCartItem->quantity);

        if ($previousQuantity === $quantity) {
            return $onlinePharmacyCartItem;
        }

        $onlinePharmacyCartItem->quantity = $quantity;
        $onlinePharmacyCartItem->save();

        $this->cartOperationalEventService->recordPharmacyQuantityChanged(
            $onlinePharmacyCartItem,
            $previousQuantity,
            $clientContext,
        );

        return $onlinePharmacyCartItem;
    }
}
