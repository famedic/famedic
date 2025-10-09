<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Account', [
            'mustVerifyEmail' => !$request->user()->hasVerifiedEmail(),
            'mustVerifyPhone' => !$request->user()->phone_verified_at,
            'genders' => Gender::casesWithLabels(),
        ]);
    }
}
