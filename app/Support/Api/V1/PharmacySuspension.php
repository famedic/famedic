<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RC2 B22 (D4): pharmacy purchases stay suspended in API v1. Any attempt to add a pharmacy item
 * through the documented cart route gets a typed 503 PHARMACY_SUSPENDED with zero effects
 * (no cart, no item, no idempotency record, no audit write).
 */
final class PharmacySuspension
{
    public const CODE = 'PHARMACY_SUSPENDED';

    private const SERVICE_LINES = ['pharmacy', 'online_pharmacy'];

    private const ITEM_TYPES = ['medication', 'pharmacy'];

    public static function requested(Request $request): bool
    {
        $serviceLine = $request->input('service_line');
        $itemType = $request->input('item_type');

        return (is_string($serviceLine) && in_array(strtolower(trim($serviceLine)), self::SERVICE_LINES, true))
            || (is_string($itemType) && in_array(strtolower(trim($itemType)), self::ITEM_TYPES, true))
            || $request->has('medication_id');
    }

    public static function response(): JsonResponse
    {
        return ApiResponse::error(
            self::CODE,
            'La compra de productos de farmacia está suspendida.',
            503,
            null,
            null,
            false,
        );
    }
}
