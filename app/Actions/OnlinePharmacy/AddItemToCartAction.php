<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Carts\CartOperationalEventService;

class AddItemToCartAction
{
    public function __construct(
        private CartOperationalEventService $cartOperationalEventService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(Customer $customer, int $vitauProductId, ?array $clientContext = null): OnlinePharmacyCartItem
    {
        $onlinePharmacyCartItem = $customer->onlinePharmacyCartItems()->whereVitauProductId($vitauProductId)->first();

        if ($onlinePharmacyCartItem) {
            return $onlinePharmacyCartItem;
        }

        $onlinePharmacyCartItem = $customer->onlinePharmacyCartItems()->save(new OnlinePharmacyCartItem([
            'vitau_product_id' => $vitauProductId,
        ]));

        $this->cartOperationalEventService->recordPharmacyItemAdded($onlinePharmacyCartItem, $clientContext);

        return $onlinePharmacyCartItem;
    }
}
