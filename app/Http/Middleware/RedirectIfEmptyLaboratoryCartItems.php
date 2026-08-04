<?php

namespace App\Http\Middleware;

use App\Enums\LaboratoryBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfEmptyLaboratoryCartItems
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var LaboratoryBrand $laboratoryBrand */
        $laboratoryBrand = $request->route('laboratory_brand');

        $hasCartItems = auth()->user()->customer
            ->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->exists();

        if ($hasCartItems) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            return redirect()->route('laboratory-tests', [
                'laboratory_brand' => $laboratoryBrand,
            ]);
        }

        if ($request->route()->getName() === 'laboratory.checkout.store') {
            return $this->redirectAfterEmptyCheckoutStore($laboratoryBrand);
        }

        return redirect()->route('laboratory.shopping-cart', [
            'laboratory_brand' => $laboratoryBrand,
        ]);
    }

    private function redirectAfterEmptyCheckoutStore(LaboratoryBrand $laboratoryBrand): Response
    {
        $recentPurchase = auth()->user()->customer->laboratoryPurchases()
            ->where('brand', $laboratoryBrand)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()
            ->first();

        if ($recentPurchase) {
            return redirect()->route('laboratory-purchases.show', [
                'laboratory_purchase' => $recentPurchase,
            ])->flashMessage('Pedido realizado con éxito.');
        }

        return redirect()->route('laboratory-purchases.index');
    }
}
