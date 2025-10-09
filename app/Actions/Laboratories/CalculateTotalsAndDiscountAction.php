<?php

namespace App\Actions\Laboratories;

use Illuminate\Database\Eloquent\Collection;

class CalculateTotalsAndDiscountAction
{
    public function __invoke(Collection $laboratoryCartItems): array
    {
        $total = 0;
        $originalTotal = 0;
        foreach ($laboratoryCartItems as  $laboratoryCartItem) {
            $total += $laboratoryCartItem->laboratoryTest->famedic_price_cents;
            $originalTotal += $laboratoryCartItem->laboratoryTest->public_price_cents;
        }

        $discount = $originalTotal - $total;

        return [
            'total' => $total,
            'formattedTotal' => formattedCentsPrice($total),
            'formattedSubtotal' => formattedCentsPrice($originalTotal),
            'formattedDiscount' => formattedCentsPrice($discount),
        ];
    }
}
