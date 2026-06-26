<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\ResolveLaboratoryCartTotalsAction;
use App\Enums\LaboratoryBrand;
use App\Services\CouponService;
use App\Services\LaboratoryCartMembershipService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryShoppingCartController extends Controller
{
    public function __invoke(
        Request $request,
        LaboratoryBrand $laboratoryBrand,
        ResolveLaboratoryCartTotalsAction $resolveLaboratoryCartTotalsAction,
        CouponService $couponService,
        LaboratoryCartMembershipService $laboratoryCartMembershipService,
    ) {
        $customer = $request->user()->customer;

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = $resolveLaboratoryCartTotalsAction($customer, $laboratoryBrand, $cartItems);

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
            'membershipCrossSell' => [
                'imageSrc' => '/images/welcome/family.jpg',
                'formattedPrice' => $laboratoryCartMembershipService->formattedPrice(),
                'priceCents' => $laboratoryCartMembershipService->priceCents(),
            ],
        ]);
    }
}
