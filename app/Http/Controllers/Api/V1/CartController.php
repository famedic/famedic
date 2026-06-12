<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\ClearAkubicaCartAction;
use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\AddCartItemRequest;
use App\Http\Requests\Api\V1\Cart\ClearCartRequest;
use App\Http\Requests\Api\V1\Cart\GetCartRequest;
use App\Http\Requests\Api\V1\Cart\GetCartTotalsRequest;
use App\Http\Resources\Api\V1\CartItemResource;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Responses\ApiResponse;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Support\Api\V1\CartCouponSupport;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(GetCartRequest $request): JsonResponse
    {
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
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $laboratoryTestId = (int) $request->validated('laboratory_test_id');

        $laboratoryTest = LaboratoryTest::query()->find($laboratoryTestId);

        if (! $laboratoryTest || $laboratoryTest->brand !== $brand) {
            return ApiResponse::error(
                'LAB_TEST_NOT_FOUND',
                'El estudio de laboratorio no fue encontrado.',
                404,
            );
        }

        $alreadyInCart = $customer->laboratoryCartItems()
            ->where('laboratory_test_id', $laboratoryTestId)
            ->exists();

        if ($alreadyInCart) {
            return ApiResponse::error(
                'ITEM_ALREADY_IN_CART',
                'El estudio ya está en el carrito.',
                409,
            );
        }

        $cartItem = $addItemToCartAction($customer, $laboratoryTestId);
        $cartItem->load('laboratoryTest');

        $items = $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

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
        $cartItem = LaboratoryCartItem::query()
            ->with('laboratoryTest')
            ->find($cartItemId);

        if (! $cartItem) {
            return ApiResponse::error(
                'CART_ITEM_NOT_FOUND',
                'Ítem de carrito no encontrado.',
                404,
            );
        }

        if ($request->user()->customer?->id !== $cartItem->customer_id) {
            return ApiResponse::error(
                'FORBIDDEN',
                'No tienes permiso para eliminar este ítem.',
                403,
            );
        }

        $brand = $cartItem->laboratoryTest->brand;
        $removedItemId = $cartItem->id;

        $deleteItemFromCartAction($cartItem);

        $items = $request->user()->customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

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
        $deletedCount = $clearAkubicaCartAction($request->user()->customer, $brand);

        return ApiResponse::success([
            'deleted' => true,
            'deleted_count' => $deletedCount,
        ]);
    }
}
