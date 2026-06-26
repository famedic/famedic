<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Services\LaboratoryCartMembershipService;
use Illuminate\Database\Eloquent\Collection;

class ResolveLaboratoryCartTotalsAction
{
    public function __construct(
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private LaboratoryCartMembershipService $laboratoryCartMembershipService,
    ) {
    }

    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        Collection $laboratoryCartItems,
    ): array {
        $laboratoryTotals = ($this->calculateTotalsAndDiscountAction)($laboratoryCartItems);

        $hasMembershipInCart = $this->laboratoryCartMembershipService->hasInCart($customer, $brand);
        $membershipPriceCents = $hasMembershipInCart
            ? $this->laboratoryCartMembershipService->priceCents()
            : 0;

        $laboratoryTotalCents = (int) $laboratoryTotals['total'];
        $checkoutTotalCents = $laboratoryTotalCents + $membershipPriceCents;

        return [
            ...$laboratoryTotals,
            'laboratoryTotalCents' => $laboratoryTotalCents,
            'membershipPriceCents' => $membershipPriceCents,
            'hasMembershipInCart' => $hasMembershipInCart,
            'formattedMembershipPrice' => $this->laboratoryCartMembershipService->formattedPrice(),
            'showMembershipCrossSell' => $this->laboratoryCartMembershipService->shouldShowCrossSell(
                $customer,
                $brand,
                $laboratoryCartItems,
            ),
            'total' => $checkoutTotalCents,
            'formattedTotal' => formattedCentsPrice($checkoutTotalCents),
            'cartTotalCents' => $checkoutTotalCents,
        ];
    }
}
