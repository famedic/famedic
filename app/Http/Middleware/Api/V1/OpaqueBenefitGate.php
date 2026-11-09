<?php

namespace App\Http\Middleware\Api\V1;

use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\Api\V1\Benefits\BenefitErrorResponse;

final class OpaqueBenefitGate
{
    public function __construct(private BenefitApprovals $approvals) {}

    public function handle($request, \Closure $next)
    {
        return $this->approvals->api() ? $next($request) : BenefitErrorResponse::make(new BenefitFailure('BENEFITS_NOT_ENABLED'));
    }
}
