<?php

namespace App\Http\Controllers;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Laboratories\LaboratoryCartMemberships\DestroyLaboratoryCartMembershipRequest;
use App\Http\Requests\Laboratories\LaboratoryCartMemberships\StoreLaboratoryCartMembershipRequest;
use App\Services\LaboratoryCartMembershipService;

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
        $membershipService->remove($request->user()->customer, $laboratoryBrand);

        return redirect()
            ->back(fallback: route('laboratory.shopping-cart', [
                'laboratory_brand' => $laboratoryBrand,
            ]))
            ->flashMessage('Membresía eliminada del carrito.');
    }
}
