<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryShoppingCartController extends Controller
{
    public function __invoke(Request $request, LaboratoryBrand $laboratoryBrand, CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction)
    {
        return Inertia::render('LaboratoryShoppingCart', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            ...$calculateTotalsAndDiscountAction($request->user()->customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->get()),
        ]);
    }
}
