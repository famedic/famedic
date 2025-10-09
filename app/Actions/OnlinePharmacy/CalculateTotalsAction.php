<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Database\Eloquent\Collection;

class CalculateTotalsAction
{
    private FetchCalculateAction $fetchCalculateAction;
    private FetchProductAction $fetchProductAction;

    public function __construct(FetchCalculateAction $fetchCalculateAction, FetchProductAction $fetchProductAction)
    {
        $this->fetchCalculateAction = $fetchCalculateAction;
        $this->fetchProductAction = $fetchProductAction;
    }

    public function __invoke(Collection $onlinePharmacyCartItems, ?string $zipcode = null): array
    {
        $details = [];
        $onlinePharmacyCartItemsCollection = collect();
        $subtotal = 0;
        foreach ($onlinePharmacyCartItems as $onlinePharmacyCartItem) {
            try {
                $product = ($this->fetchProductAction)($onlinePharmacyCartItem->vitau_product_id);
                $subtotal += $product['price'] * $onlinePharmacyCartItem->quantity;
                $details[] = [
                    'product' => $product['id'],
                    'quantity' => $onlinePharmacyCartItem->quantity,
                ];
                $product['cartItem'] = $onlinePharmacyCartItem;
                $onlinePharmacyCartItemsCollection->push($product);
            } catch (Exception $e) {
                $onlinePharmacyCartItem->delete();
            }
        }

        try {
            $cart = $zipcode ? ($this->fetchCalculateAction)($zipcode, $details) : null;
        } catch (Exception $e) {
            $cart = null;
        }

        return [
            'total' => $cart ?  $cart['total'] : 0,
            'subtotal' => $cart ? $cart['subtotal'] : $subtotal,
            'formattedTotal' => $cart ? formattedCentsPrice($cart['total'] * 100) : "",
            'formattedSubtotal' => formattedCentsPrice(($cart ? $cart['subtotal'] : $subtotal) * 100),
            'formattedTax' => $cart ? formattedCentsPrice($cart['iva'] * 100) : "",
            'formattedDelivery' => $cart ? formattedCentsPrice($cart['shipping_price'] * 100) : "",
        ];
    }
}
