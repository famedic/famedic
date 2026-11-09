<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Http\Responses\ApiResponse;

final class BenefitErrorResponse
{
    public static function make(\Throwable $error): \Illuminate\Http\JsonResponse
    {
        $code = $error instanceof BenefitFailure ? $error->errorCode : 'BENEFIT_SERVICE_UNAVAILABLE';
        $status = match (true) {
            $code === 'UNAUTHENTICATED' => 401,in_array($code, ['FORBIDDEN', 'BENEFITS_NOT_ENABLED', 'CONSENT_ISSUANCE_PENDING_APPROVAL', 'CONSUMER_NOT_AUTHORIZED', 'FIRST_PARTY_CONFIRMATION_REQUIRED', 'FINANCIAL_WRITES_PENDING_APPROVAL'], true) => 403,
            str_ends_with($code, 'NOT_FOUND') => 404,in_array($code, ['VALIDATION_ERROR', 'CONFIRMATION_REQUIRED', 'IDEMPOTENCY_KEY_REQUIRED'], true) => 422,$code === 'BENEFIT_SERVICE_UNAVAILABLE' => 503,default => 409
        };

        return ApiResponse::error($code, 'No se pudo completar la operación de beneficios.', $status, null, null, $status === 503);
    }
}
