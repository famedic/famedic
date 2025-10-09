<?php

namespace App\Http\Controllers;

use App\Actions\OnlinePharmacy\CalculateTotalsAction;
use App\Models\Address;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnlinePharmacyShoppingCartController extends Controller
{
    public function __invoke(Request $request, CalculateTotalsAction $calculateTotalsAction)
    {
        return Inertia::render('OnlinePharmacyShoppingCart', [
            ...$calculateTotalsAction($request->user()->customer->onlinePharmacyCartItems()->get()),
        ]);
    }
}
