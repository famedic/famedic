<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryShoppingCartController extends Controller
{
    public function __invoke(
        Request $request,
        LaboratoryBrand $laboratoryBrand,
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        CouponService $couponService,
    ) {
        $totals = $calculateTotalsAndDiscountAction(
            $request->user()->customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->get()
        );

        $balancePresentation = $couponService->emptyCheckoutCreditPresentation($totals['total']);

        try {
            $balancePresentation = $couponService->buildCheckoutCreditPresentation(
                $request->user()->id,
                $totals['total'],
            );
        } catch (\Throwable) {
            // No bloquear el carrito si falla la consulta de saldo.
        }

        return Inertia::render('LaboratoryShoppingCart', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            ...$totals,
            ...$balancePresentation,
            'balanceCreditPresentation' => $balancePresentation,
        ]);
    }
}
