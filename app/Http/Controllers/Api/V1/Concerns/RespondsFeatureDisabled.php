<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

trait RespondsFeatureDisabled
{
    protected function featureDisabled(string $message): JsonResponse
    {
        return ApiResponse::error(
            'FEATURE_DISABLED',
            $message,
            503,
        );
    }

    protected function catalogUnavailable(): JsonResponse
    {
        return ApiResponse::error(
            'CATALOG_UNAVAILABLE',
            'El catálogo de medicamentos no está disponible temporalmente.',
            503,
        );
    }
}
