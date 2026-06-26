<?php

namespace App\Http\Controllers;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Laboratories\LaboratoryCartMemberships\DestroyLaboratoryCartMembershipRequest;
use App\Http\Requests\Laboratories\LaboratoryCartMemberships\StoreLaboratoryCartMembershipRequest;
use App\Services\LaboratoryCartMembershipService;
use Illuminate\Support\Facades\Schema;

class LaboratoryCartMembershipController extends Controller
{
    public function store(
        StoreLaboratoryCartMembershipRequest $request,
        LaboratoryBrand $laboratoryBrand,
        LaboratoryCartMembershipService $membershipService,
    ) {
        $membershipService->add($request->user()->customer, $laboratoryBrand);

        return redirect()
            ->route('laboratory.shopping-cart', [
                'laboratory_brand' => $laboratoryBrand,
            ])
            ->flashMessage('Membresía agregada correctamente.');
    }

    public function destroy(
        DestroyLaboratoryCartMembershipRequest $request,
        LaboratoryBrand $laboratoryBrand,
        LaboratoryCartMembershipService $membershipService,
    ) {
        $customer = $request->user()->customer;

        $membershipService->remove($customer, $laboratoryBrand);

        if (Schema::hasTable('laboratory_checkout_drafts')) {
            $customer->laboratoryCheckoutDrafts()
                ->where('laboratory_brand', $laboratoryBrand->value)
                ->update(['promo_validation_token' => null]);
        }

        return redirect()
            ->back(fallback: route('laboratory.shopping-cart', [
                'laboratory_brand' => $laboratoryBrand,
            ]))
            ->flashMessage('Membresía eliminada del carrito.');
    }
}
