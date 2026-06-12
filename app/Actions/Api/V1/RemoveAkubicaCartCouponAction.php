<?php

namespace App\Actions\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Support\Api\V1\CartCouponSupport;

class RemoveAkubicaCartCouponAction
{
    public function __construct(
        private readonly CartCouponSupport $cartCouponSupport,
    ) {}

    /**
     * @return array{
     *     brand: string,
     *     removed: bool,
     *     coupon: null,
     *     totals?: array<string, mixed>,
     * }
     */
    public function __invoke(Customer $customer, LaboratoryBrand $brand): array
    {
        $draft = $this->cartCouponSupport->draftForBrand($customer, $brand);
        $hadCoupon = $draft?->coupon_id !== null;

        if ($hadCoupon) {
            $this->cartCouponSupport->persistCoupon($customer, $brand, null);
        }

        $payload = [
            'brand' => $brand->value,
            'removed' => $hadCoupon,
            'coupon' => null,
        ];

        if ($hadCoupon) {
            $items = $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get();
            $totals = $this->cartCouponSupport->buildTotalsWithCoupon($brand, $items, null);
            $payload['totals'] = [
                'currency' => $totals['currency'],
                'items_count' => $totals['items_count'],
                'subtotal_cents' => $totals['subtotal_cents'],
                'discount_cents' => $totals['discount_cents'],
                'coupon_discount_cents' => 0,
                'total_cents' => $totals['total_cents'],
            ];
        }

        return $payload;
    }
}
