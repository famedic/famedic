<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BenavidesCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BenavidesBenefitController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $assignment = $user->benavidesCode()->first();

        return Inertia::render('User/Benefits/Benavides', [
            'assignment' => $assignment ? [
                'code' => $assignment->code,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ] : null,
            'benefitEnabled' => (bool) config('famedic.benavides_benefit.enabled', false),
            'hasAvailableCodes' => BenavidesCode::query()
                ->whereNull('user_id')
                ->exists(),
            'holderName' => $user->full_name ?: ($user->name ?: 'Usuario FAMEDIC'),
        ]);
    }
}
