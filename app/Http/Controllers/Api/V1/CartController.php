<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\ClearAkubicaCartAction;
use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MedicalServiceCartConflictException;
use App\Exceptions\MedicalServiceMembershipActiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\AddCartItemRequest;
use App\Http\Requests\Api\V1\Cart\ClearCartRequest;
use App\Http\Requests\Api\V1\Cart\GetCartRequest;
use App\Http\Requests\Api\V1\Cart\GetCartTotalsRequest;
use App\Http\Resources\Api\V1\CartItemResource;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Resources\Api\V1\MedicalServiceCartResource;
use App\Http\Responses\ApiResponse;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Services\Api\V1\MedicalServiceCartService;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\CartCheckoutAuditRecorder;
use App\Support\Api\V1\CartCouponSupport;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartCheckoutAuditRecorder $cartCheckoutAudit,
    ) {}

    public function index(GetCartRequest $request): JsonResponse
    {
        if ($request->validated('service_line') !== null) {
            if ($request->validated('service_line') !== 'medical_services' || $request->validated('brand') !== null) {
                return $this->serviceLineMismatch();
            }

            $cart = app(MedicalServiceCartService::class)->activeCart($request->user()->customer);

            return ApiResponse::success((new MedicalServiceCartResource($cart->load('items')))->resolve($request));
        }

        $brand = LaboratoryBrand::from($request->validated('brand'));
        $items = $request->user()->customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

        return ApiResponse::success(
            CartResource::forBrand($brand, $items)->resolve($request),
        );
    }

    public function store(
        AddCartItemRequest $request,
        AddItemToCartAction $addItemToCartAction,
    ): JsonResponse {
        $customer = $request->user()->customer;

        if ($request->validated('service_line') !== null) {
            if ($request->validated('service_line') !== 'medical_services' || $request->validated('brand') !== null) {
                return $this->serviceLineMismatch();
            }

            if ($request->validated('item_type') !== 'membership'
                || $request->validated('membership_plan_code') !== 'basic_annual'
                || (int) ($request->validated('quantity') ?? 1) !== 1
            ) {
                return ApiResponse::error('VALIDATION_ERROR', 'Los datos enviados no son válidos.', 422);
            }

            try {
                $cart = app(MedicalServiceCartService::class)->addBasicPlan($customer);
            } catch (MedicalServiceMembershipActiveException $exception) {
                $code = $exception->reason === 'sponsored_membership_active'
                    ? 'SPONSORED_MEMBERSHIP_ACTIVE'
                    : 'MEMBERSHIP_ALREADY_ACTIVE';

                return ApiResponse::error($code, 'No se puede agregar una membresía médica activa.', 409);
            } catch (MedicalServiceCartConflictException $exception) {
                return ApiResponse::error($exception->errorCode, $exception->getMessage(), 409);
            }

            $this->cartCheckoutAudit->recordCartItemAdded(
                request: $request,
                outcome: AuditOutcome::SUCCEEDED,
                httpStatus: 201,
                metadata: ['service_line' => 'medical_services', 'item_type' => 'membership'],
            );

            return ApiResponse::success(
                (new MedicalServiceCartResource($cart))->resolve($request),
                status: 201,
            );
        }

        $brand = LaboratoryBrand::from($request->validated('brand'));
        $laboratoryTestId = (int) $request->validated('laboratory_test_id');

        $laboratoryTest = LaboratoryTest::query()->find($laboratoryTestId);

        if (! $laboratoryTest || $laboratoryTest->brand !== $brand) {
            $response = ApiResponse::error(
                'LAB_TEST_NOT_FOUND',
                'El estudio de laboratorio no fue encontrado.',
                404,
            );
            $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
            $this->cartCheckoutAudit->recordCartItemAdded(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                laboratoryBrand: $brand->value,
            );

            return $response;
        }

        $alreadyInCart = $customer->laboratoryCartItems()
            ->where('laboratory_test_id', $laboratoryTestId)
            ->exists();

        if ($alreadyInCart) {
            $response = ApiResponse::error(
                'ITEM_ALREADY_IN_CART',
                'El estudio ya está en el carrito.',
                409,
            );
            $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
            $this->cartCheckoutAudit->recordCartItemAdded(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                laboratoryBrand: $brand->value,
                laboratoryTestRowId: $laboratoryTestId,
            );

            return $response;
        }

        $cartItem = $addItemToCartAction($customer, $laboratoryTestId);
        $cartItem->load('laboratoryTest');

        $items = $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

        $this->cartCheckoutAudit->recordCartItemAdded(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 201,
            resourceKey: (string) $cartItem->id,
            laboratoryBrand: $brand->value,
            cartItemRowId: (int) $cartItem->id,
            laboratoryTestRowId: $laboratoryTestId,
            itemCount: $items->count(),
        );

        return ApiResponse::success([
            'item' => (new CartItemResource($cartItem))->resolve($request),
            'cart' => CartResource::forBrand($brand, $items)->resolve($request),
        ], status: 201);
    }

    public function destroy(
        Request $request,
        int $cartItemId,
        DeleteItemFromCartAction $deleteItemFromCartAction,
    ): JsonResponse {
        $medicalItem = \App\Models\MedicalServiceCartItem::query()->with('cart')->find($cartItemId);
        if ($medicalItem) {
            if ((int) $medicalItem->cart->customer_id !== (int) $request->user()->customer?->id) {
                $response = ApiResponse::error('FORBIDDEN', 'No tienes permiso para eliminar este ítem.', 403);
                $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
                $this->cartCheckoutAudit->recordCartItemRemoved(
                    request: $request,
                    outcome: $classified['outcome'],
                    httpStatus: 403,
                    errorCode: $classified['error_code'],
                );

                return $response;
            }

            try {
                $cart = app(MedicalServiceCartService::class)->removeItem($request->user()->customer, $cartItemId);
            } catch (MedicalServiceCartConflictException $exception) {
                $status = match ($exception->errorCode) {
                    'FORBIDDEN' => 403,
                    'CART_SERVICE_LINE_MISMATCH', 'MEMBERSHIP_ALREADY_IN_CART' => 409,
                    default => 404,
                };

                return ApiResponse::error($exception->errorCode, $exception->getMessage(), $status);
            }

            $this->cartCheckoutAudit->recordCartItemRemoved(
                request: $request,
                outcome: AuditOutcome::SUCCEEDED,
                httpStatus: 200,
                metadata: ['service_line' => 'medical_services', 'item_type' => 'membership'],
            );

            return ApiResponse::success(
                (new MedicalServiceCartResource($cart))->resolve($request),
            );
        }

        $cartItem = LaboratoryCartItem::query()
            ->with('laboratoryTest')
            ->find($cartItemId);

        if (! $cartItem) {
            $response = ApiResponse::error(
                'CART_ITEM_NOT_FOUND',
                'Ítem de carrito no encontrado.',
                404,
            );
            $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
            $this->cartCheckoutAudit->recordCartItemRemoved(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
            );

            return $response;
        }

        if ($request->user()->customer?->id !== $cartItem->customer_id) {
            $response = ApiResponse::error(
                'FORBIDDEN',
                'No tienes permiso para eliminar este ítem.',
                403,
            );
            $classified = $this->cartCheckoutAudit->classifyErrorResponse($response);
            // Cross-user: no foreign cart_item / test IDs in resource or metadata.
            $this->cartCheckoutAudit->recordCartItemRemoved(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
            );

            return $response;
        }

        $brand = $cartItem->laboratoryTest->brand;
        $removedItemId = $cartItem->id;
        $laboratoryTestRowId = (int) $cartItem->laboratory_test_id;

        $deleteItemFromCartAction($cartItem);

        $items = $request->user()->customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

        $this->cartCheckoutAudit->recordCartItemRemoved(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: (string) $removedItemId,
            laboratoryBrand: $brand->value,
            cartItemRowId: (int) $removedItemId,
            laboratoryTestRowId: $laboratoryTestRowId,
            itemCount: $items->count(),
        );

        return ApiResponse::success([
            'removed_item_id' => $removedItemId,
            'cart' => CartResource::forBrand($brand, $items)->resolve($request),
        ]);
    }

    public function totals(
        GetCartTotalsRequest $request,
        CheckoutPreparation $checkoutPreparation,
        CartCouponSupport $cartCouponSupport,
    ): JsonResponse {
        if ($request->validated('service_line') !== null) {
            if ($request->validated('service_line') !== 'medical_services' || $request->validated('brand') !== null) {
                return $this->serviceLineMismatch();
            }

            $cart = app(MedicalServiceCartService::class)->activeCart($request->user()->customer);

            return ApiResponse::success((new MedicalServiceCartResource($cart->load('items')))->resolve($request)['totals'] + [
                'service_line' => 'medical_services',
                'applied_benefits' => [],
            ]);
        }

        $brand = LaboratoryBrand::from($request->validated('brand'));

        return ApiResponse::success(
            $checkoutPreparation->cartTotals($request->user()->customer, $brand, $cartCouponSupport),
        );
    }

    public function clear(
        ClearCartRequest $request,
        ClearAkubicaCartAction $clearAkubicaCartAction,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $customer = $request->user()->customer;
        $deletedCount = $clearAkubicaCartAction($customer, $brand);

        $this->cartCheckoutAudit->recordCartCleared(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: $this->cartCheckoutAudit->cartResourceKey((int) $customer->id, $brand),
            laboratoryBrand: $brand->value,
            itemCount: $deletedCount,
        );

        return ApiResponse::success([
            'deleted' => true,
            'deleted_count' => $deletedCount,
        ]);
    }

    private function serviceLineMismatch(): JsonResponse
    {
        return ApiResponse::error(
            'CART_SERVICE_LINE_MISMATCH',
            'La línea de servicio no corresponde al carrito solicitado.',
            409,
        );
    }
}
