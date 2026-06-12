<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\ApplyAkubicaCartCouponAction;
use App\Actions\Api\V1\RemoveAkubicaCartCouponAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\ApplyCartCouponRequest;
use App\Http\Requests\Api\V1\Cart\GetCartCouponRequest;
use App\Http\Requests\Api\V1\Cart\RemoveCartCouponRequest;
use App\Http\Responses\ApiResponse;
use App\Support\Api\V1\CartCouponSupport;
use Illuminate\Http\JsonResponse;

class CartCouponController extends Controller
{
    public function show(
        GetCartCouponRequest $request,
        CartCouponSupport $cartCouponSupport,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $customer = $request->user()->customer;
        $draft = $cartCouponSupport->draftForBrand($customer, $brand);
        $items = $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get();
        $cartTotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);

        return ApiResponse::success([
            'brand' => $brand->value,
            'coupon' => $cartCouponSupport->formatCouponPayload($draft?->coupon, $cartTotalCents),
        ]);
    }

    public function apply(
        ApplyCartCouponRequest $request,
        ApplyAkubicaCartCouponAction $applyCartCouponAction,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $result = $applyCartCouponAction(
            $request->user()->customer,
            $request->user(),
            $brand,
            $request->validated('code'),
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'EMPTY_CART' => ApiResponse::error(
                    'EMPTY_CART',
                    'No se puede aplicar cupón con un carrito vacío.',
                    409,
                ),
                'COUPON_NOT_FOUND' => ApiResponse::error(
                    'COUPON_NOT_FOUND',
                    'El cupón no fue encontrado.',
                    404,
                ),
                'COUPON_EXPIRED' => ApiResponse::error(
                    'COUPON_EXPIRED',
                    'El cupón ya no está disponible.',
                    409,
                ),
                'COUPON_NOT_APPLICABLE' => ApiResponse::error(
                    'COUPON_NOT_APPLICABLE',
                    'El cupón no puede aplicarse a este carrito.',
                    409,
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

    public function remove(
        RemoveCartCouponRequest $request,
        RemoveAkubicaCartCouponAction $removeCartCouponAction,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));

        return ApiResponse::success(
            $removeCartCouponAction($request->user()->customer, $brand),
        );
    }
}
