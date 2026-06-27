<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\PromoCodeException;
use App\Models\LaboratoryCheckoutDraft;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PromoCodeCheckoutController extends Controller
{
    public function validateCode(
        Request $request,
        LaboratoryBrand $laboratoryBrand,
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        PromoCodeService $promoCodeService,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $customer = $request->user()->customer;
        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        if ($cartItems->isEmpty()) {
            throw ValidationException::withMessages([
                'code' => 'Tu carrito está vacío.',
            ]);
        }

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $laboratoryBrand)
            ->first();

        if ($draft?->coupon_id !== null) {
            throw ValidationException::withMessages([
                'code' => 'No puedes combinar un crédito asignado con un código promocional.',
            ]);
        }

        $totals = $calculateTotalsAndDiscountAction($cartItems);
        $cartTotalCents = (int) $totals['total'];
        $cartHash = $promoCodeService->buildLaboratoryCartHash($cartItems, $cartTotalCents);

        try {
            $result = $promoCodeService->validateForCheckout(
                $request->user(),
                $customer,
                $validated['code'],
                $cartTotalCents,
                $cartHash,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (PromoCodeException $e) {
            throw ValidationException::withMessages([
                'code' => $e->getMessage(),
            ]);
        }

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $laboratoryBrand,
            ],
            [
                'promo_validation_token' => $result['validation_token'],
                'coupon_id' => null,
            ],
        );

        return response()->json($result);
    }

    public function destroy(
        Request $request,
        LaboratoryBrand $laboratoryBrand,
        PromoCodeService $promoCodeService,
    ): JsonResponse {
        $validated = $request->validate([
            'validation_token' => ['required', 'string', 'max:64'],
        ]);

        $customer = $request->user()->customer;

        $promoCodeService->clearValidation(
            $request->user(),
            $validated['validation_token'],
        );

        LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $laboratoryBrand)
            ->where('promo_validation_token', $validated['validation_token'])
            ->update(['promo_validation_token' => null]);

        return response()->json(['cleared' => true]);
    }
}
