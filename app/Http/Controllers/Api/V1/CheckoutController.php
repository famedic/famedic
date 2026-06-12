<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\GenerateAkubicaCheckoutPaymentLinkAction;
use App\Actions\Api\V1\SyncAkubicaCheckoutDraftAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkout\GeneratePaymentLinkRequest;
use App\Http\Requests\Api\V1\Checkout\PrepareCheckoutRequest;
use App\Http\Requests\Api\V1\Checkout\SyncCheckoutDraftRequest;
use App\Http\Resources\Api\V1\CheckoutPaymentLinkResource;
use App\Http\Responses\ApiResponse;
use App\Support\Api\V1\CartCouponSupport;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function prepare(
        PrepareCheckoutRequest $request,
        CheckoutPreparation $checkoutPreparation,
        CartCouponSupport $cartCouponSupport,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));

        return ApiResponse::success(
            $checkoutPreparation->prepare(
                $request->user()->customer,
                $brand,
                $request,
                $cartCouponSupport,
            ),
        );
    }

    public function syncDraft(
        SyncCheckoutDraftRequest $request,
        SyncAkubicaCheckoutDraftAction $syncAkubicaCheckoutDraftAction,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $validated = $request->validated();

        $payload = [];

        foreach (['contact_id', 'address_id', 'requires_invoice', 'tax_profile_id', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        $result = $syncAkubicaCheckoutDraftAction(
            $request->user()->customer,
            $brand,
            $payload,
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'EMPTY_CART' => ApiResponse::error(
                    'EMPTY_CART',
                    'No se puede preparar checkout con un carrito vacío.',
                    409,
                ),
                'CONTACT_NOT_FOUND' => ApiResponse::error(
                    'CONTACT_NOT_FOUND',
                    'El contacto no fue encontrado.',
                    404,
                ),
                'ADDRESS_NOT_FOUND' => ApiResponse::error(
                    'ADDRESS_NOT_FOUND',
                    'La dirección no fue encontrada.',
                    404,
                ),
                'TAX_PROFILE_NOT_FOUND' => ApiResponse::error(
                    'TAX_PROFILE_NOT_FOUND',
                    'El perfil fiscal no fue encontrado.',
                    404,
                ),
                default => ApiResponse::error(
                    'INTERNAL_ERROR',
                    'Ocurrió un error inesperado.',
                    500,
                ),
            };
        }

        return ApiResponse::success($result);
    }

    public function paymentLink(
        GeneratePaymentLinkRequest $request,
        GenerateAkubicaCheckoutPaymentLinkAction $generatePaymentLinkAction,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $expiresInMinutes = (int) $request->validated('expires_in_minutes');
        $sanctumTokenId = $request->user()->currentAccessToken()?->id;

        $result = $generatePaymentLinkAction(
            $request->user()->customer,
            $brand,
            $expiresInMinutes,
            is_numeric($sanctumTokenId) ? (int) $sanctumTokenId : null,
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'EMPTY_CART' => ApiResponse::error(
                    'EMPTY_CART',
                    'No se puede generar liga de pago con un carrito vacío.',
                    409,
                ),
                'CHECKOUT_NOT_READY' => ApiResponse::error(
                    'CHECKOUT_NOT_READY',
                    'El checkout no está listo para generar liga de pago.',
                    409,
                    details: ['missing' => $result['missing'] ?? []],
                ),
                'APPOINTMENT_REQUIRED' => ApiResponse::error(
                    'APPOINTMENT_REQUIRED',
                    'Este carrito requiere una cita antes de continuar al pago.',
                    409,
                ),
                default => ApiResponse::error(
                    'INTERNAL_ERROR',
                    'Ocurrió un error inesperado.',
                    500,
                ),
            };
        }

        return ApiResponse::success([
            'payment_link' => (new CheckoutPaymentLinkResource($result))->resolve($request),
        ]);
    }
}
