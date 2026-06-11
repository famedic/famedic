<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

trait RespondsNotImplemented
{
    protected function notImplemented(): JsonResponse
    {
        return ApiResponse::error(
            'NOT_IMPLEMENTED',
            'Endpoint pendiente de implementación.',
            501,
        );
    }
}
