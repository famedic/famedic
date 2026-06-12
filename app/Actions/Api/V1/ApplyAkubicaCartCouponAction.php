<?php

namespace App\Actions\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\User;
use App\Support\Api\V1\CartCouponSupport;
use App\Support\Api\V1\CheckoutPreparation;

class ApplyAkubicaCartCouponAction
{
    public function __construct(
        private readonly CheckoutPreparation $checkoutPreparation,
        private readonly CartCouponSupport $cartCouponSupport,
    ) {}

    /**
     * @return array{
     *     brand: string,
     *     coupon: array<string, mixed>,
     *     totals: array<string, mixed>,
     * }|array{error: string}
     */
    public function __invoke(Customer $customer, User $user, LaboratoryBrand $brand, string $code): array
    {
        $items = $this->checkoutPreparation->cartItems($customer, $brand);

        if ($items->isEmpty()) {
            return ['error' => 'EMPTY_CART'];
        }

        $coupon = $this->cartCouponSupport->findCouponByCodeForUser($user, $code);

        if (! $coupon) {
            return ['error' => 'COUPON_NOT_FOUND'];
        }

        $cartTotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);
        $validation = $this->cartCouponSupport->validateCouponForCart($user, $coupon, $cartTotalCents);

        if (isset($validation['error'])) {
            return ['error' => $validation['error']];
        }

        $this->cartCouponSupport->persistCoupon($customer, $brand, $coupon->id);

        $totals = $this->cartCouponSupport->buildTotalsWithCoupon(
            $brand,
            $items,
            $coupon->fresh(),
        );

        return [
            'brand' => $brand->value,
            'coupon' => $totals['coupon'],
            'totals' => [
                'currency' => $totals['currency'],
                'items_count' => $totals['items_count'],
                'subtotal_cents' => $totals['subtotal_cents'],
                'discount_cents' => $totals['discount_cents'],
                'coupon_discount_cents' => $totals['coupon_discount_cents'],
                'total_cents' => $totals['total_cents'],
            ],
        ];
    }
}
