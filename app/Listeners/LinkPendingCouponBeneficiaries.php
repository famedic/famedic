<?php

namespace App\Listeners;

use App\Services\CouponBeneficiaryService;
use Illuminate\Auth\Events\Verified;

class LinkPendingCouponBeneficiaries
{
    public function __construct(
        private CouponBeneficiaryService $couponBeneficiaryService,
    ) {}

    public function handle(Verified $event): void
    {
        $this->couponBeneficiaryService->linkPendingBeneficiariesForUser($event->user);
    }
}
