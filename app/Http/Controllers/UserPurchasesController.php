<?php

namespace App\Http\Controllers;

use App\Services\UserPurchases\PendingPurchasesQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserPurchasesController extends Controller
{
    public function __invoke(Request $request, PendingPurchasesQuery $pendingPurchases): Response
    {
        $customer = $request->user()->customer;
        $readModel = $pendingPurchases->forCustomer($customer);

        return Inertia::render('User/Purchases', $readModel);
    }
}
