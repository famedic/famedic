<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponApplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Dormant read-only adapter reusing confirmed current FAMEDIC eligibility rules.
 * Caller must supply authenticated user and authoritative cart subtotal.
 * No route/provider binding; results are NOT an HTTP response DTO.
 */
final class MedicalBenefitEligibilityReader
{
    public function __construct(private readonly CouponApplicationService $eligibility) {}

    /** @return list<EligibleMedicalBenefit> */
    public function eligible(User $user, int $subtotalCents): array
    {
        if (! $user->exists || $subtotalCents <= 0) {
            return [];
        }
        $maxAssigned = config('akubica_connectors.catalog_limits.assigned_sources', 200);
        $maxEligible = config('akubica_connectors.catalog_limits.eligible_options', 50);
        if (! is_int($maxAssigned) || $maxAssigned < 1 || $maxAssigned > 200 || ! is_int($maxEligible) || $maxEligible < 1 || $maxEligible > 50) {
            throw new \App\Domain\Akubica\Benefits\BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
        }
        if (Coupon::query()->whereHas('couponUsers', fn ($q) => $q->where('user_id', $user->getKey()))->limit($maxAssigned + 1)->pluck('id')->count() > $maxAssigned) {
            throw new \App\Domain\Akubica\Benefits\BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
        }
        $result = [];
        $query = Coupon::query()->whereHas('couponUsers', fn ($q) => $q->where('user_id', $user->getKey()))->orderBy('id');
        foreach ($query->cursor() as $coupon) {
            try {
                $source = $this->eligibility->validateMedicalServicePreview($user, (int) $coupon->getKey(), $subtotalCents);
            } catch (CouponApplicationException|ModelNotFoundException) {
                continue;
            }
            if (count($result) >= $maxEligible) {
                throw new \App\Domain\Akubica\Benefits\BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
            }
            $result[] = new EligibleMedicalBenefit((int) $source->getKey(), $source->type === CouponType::Balance ? 'credit' : 'coupon', min((int) $source->remaining_cents, $subtotalCents));
        }

        // Database/calculation errors propagate; never substitute an invented empty success.
        return $result;
    }
}
