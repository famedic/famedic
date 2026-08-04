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
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\CartCheckoutAuditRecorder;
use App\Support\Api\V1\CartCouponSupport;
use Illuminate\Http\JsonResponse;

class CartCouponController extends Controller
{
    public function __construct(
        private readonly CartCheckoutAuditRecorder $cartCheckoutAudit,
    ) {}

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
        CartCouponSupport $cartCouponSupport,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $customer = $request->user()->customer;
        $cartResourceKey = $this->cartCheckoutAudit->cartResourceKey((int) $customer->id, $brand);

        $result = $applyCartCouponAction(
            $customer,
            $request->user(),
            $brand,
            $request->validated('code'),
        );

        if (isset($result['error'])) {
            $response = match ($result['error']) {
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

            $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
            $this->cartCheckoutAudit->recordCartBenefitApplied(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: $cartResourceKey,
                laboratoryBrand: $brand->value,
            );

            return $response;
        }

        $totals = $result['totals'];
        $draft = $cartCouponSupport->draftForBrand($customer, $brand);

        $this->cartCheckoutAudit->recordCartBenefitApplied(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: $cartResourceKey,
            laboratoryBrand: $brand->value,
            couponRowId: $draft?->coupon_id !== null ? (int) $draft->coupon_id : null,
            appliedAmountMinor: (int) ($totals['coupon_discount_cents'] ?? 0),
            itemCount: (int) ($totals['items_count'] ?? 0),
            subtotalMinor: (int) ($totals['subtotal_cents'] ?? 0),
            discountMinor: (int) ($totals['discount_cents'] ?? 0),
            creditMinor: (int) ($totals['coupon_discount_cents'] ?? 0),
            totalMinor: (int) ($totals['total_cents'] ?? 0),
        );

        return ApiResponse::success($result);
    }

    public function remove(
        RemoveCartCouponRequest $request,
        RemoveAkubicaCartCouponAction $removeCartCouponAction,
        CartCouponSupport $cartCouponSupport,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $customer = $request->user()->customer;
        $cartResourceKey = $this->cartCheckoutAudit->cartResourceKey((int) $customer->id, $brand);

        $draftBefore = $cartCouponSupport->draftForBrand($customer, $brand);
        $previousCouponId = $draftBefore?->coupon_id !== null ? (int) $draftBefore->coupon_id : null;
        $previousAmountMinor = 0;
        if ($draftBefore?->coupon !== null) {
            $itemsBefore = $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get();
            $cartTotalCents = (int) $itemsBefore->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);
            $previousAmountMinor = $cartCouponSupport->couponDiscountCents($draftBefore->coupon, $cartTotalCents);
        }

        $result = $removeCartCouponAction($customer, $brand);

        // No-op when no coupon was applied: successful HTTP response, no audit event.
        if (! ($result['removed'] ?? false)) {
            return ApiResponse::success($result);
        }

        $totals = $result['totals'] ?? [];

        $this->cartCheckoutAudit->recordCartBenefitRemoved(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: $cartResourceKey,
            laboratoryBrand: $brand->value,
            couponRowId: $previousCouponId,
            removedAmountMinor: $previousAmountMinor,
            itemCount: isset($totals['items_count']) ? (int) $totals['items_count'] : null,
            subtotalMinor: isset($totals['subtotal_cents']) ? (int) $totals['subtotal_cents'] : null,
            discountMinor: isset($totals['discount_cents']) ? (int) $totals['discount_cents'] : null,
            totalMinor: isset($totals['total_cents']) ? (int) $totals['total_cents'] : null,
        );

        return ApiResponse::success($result);
    }
}
