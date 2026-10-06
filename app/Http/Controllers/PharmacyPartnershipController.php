<?php

namespace App\Http\Controllers;

use App\Models\BenavidesCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PharmacyPartnershipController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $hasAssignment = $user?->benavidesCode()->exists() ?? false;

        return Inertia::render('Pharmacies/Index', [
            'benavidesBenefit' => [
                'activationEnabled' => (bool) config('famedic.benavides_benefit.enabled', false),
                'hasAssignment' => $hasAssignment,
                'hasAvailableCodes' => $hasAssignment
                    ? false
                    : BenavidesCode::query()->whereNull('user_id')->exists(),
            ],
        ]);
    }
}
