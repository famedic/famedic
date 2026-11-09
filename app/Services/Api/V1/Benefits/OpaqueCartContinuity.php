<?php

namespace App\Services\Api\V1\Benefits;

use App\Http\Resources\Api\V1\Benefits\BenefitSnapshotDto;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Auth\ConsumerRequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpaqueCartContinuity
{
    public static function enabled(): bool
    {
        return config('akubica_connectors.continuity.enabled') === true && config('akubica_connectors.continuity.certified') === true;
    }

    public static function leo(Request $request): bool
    {
        return $request->attributes->get(ConsumerRequestScope::ATTRIBUTE)?->consumerKind === 'leo';
    }

    public function response(Request $request, bool $checkout = false): JsonResponse
    {
        try {
            if (! self::enabled() || ! self::leo($request)) {
                throw new \App\Domain\Akubica\Benefits\BenefitFailure('CONSUMER_NOT_AUTHORIZED');
            }
            $snapshot = app(LaravelOpaqueBenefits::class)->snapshot($request);
            $data = ['audience' => 'leo', 'service_line' => 'medical_services', 'cart' => BenefitSnapshotDto::from($snapshot), 'assistant_can_pay' => false, 'can_continue_on_platform' => true];
            if ($checkout) {
                $data += ['can_continue_to_payment_platform' => false, 'availability' => 'preview_only'];
            }

            return ApiResponse::success($data)->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $e) {
            return BenefitErrorResponse::make($e);
        }
    }
}
