<?php

namespace App\Http\Controllers\Laboratories\LaboratoryPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreInvoiceRequestRequest;
use App\Models\Administrator;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Notifications\LaboratoryPurchaseInvoiceRequested;

class InvoiceRequestController extends Controller
{
    public function __invoke(StoreInvoiceRequestRequest $request, LaboratoryPurchase $laboratoryPurchase, CreateInvoiceRequestAction $action)
    {
        $action($laboratoryPurchase, auth()->user()->customer->taxProfiles()->find($request->tax_profile));

        $roles = Permission::whereName('laboratory-purchases.manage.invoices')->sole()->roles;

        $users = collect();
        foreach ($roles as $role) {
            $administrators = Administrator::role($role->name)->get();
            $users = $users->merge($administrators->pluck('user'));
        }

        $users = $users->unique('id');

        foreach ($users as $user) {

            $user->notify(new LaboratoryPurchaseInvoiceRequested($laboratoryPurchase));
        }

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Se ha solicitado la factura.');
    }
}
