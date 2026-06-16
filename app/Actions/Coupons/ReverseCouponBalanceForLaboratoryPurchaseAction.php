<?php

namespace App\Actions\Coupons;

use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\CouponApplicationService;

class ReverseCouponBalanceForLaboratoryPurchaseAction
{
    public function __construct(
        private CouponApplicationService $couponApplicationService
    ) {}

    public function __invoke(
        LaboratoryPurchase $purchase,
        ?User $actor = null,
        string $reason = 'laboratory_purchase_cancelled'
    ): int {
        return $this->couponApplicationService->reverseForLaboratoryPurchase(
            $purchase,
            $actor,
            $reason
        );
    }
}
