<?php

namespace App\Http\Controllers;

use App\Enums\Gender;
use App\Http\Requests\Auth\StoreCompleteProfileRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Propaganistas\LaravelPhone\PhoneNumber;

class CompleteProfileController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->profile_is_complete) {
            return redirect()->route('home');
        }

        return Inertia::render(
            'Auth/CompleteProfile',
            [
                'genders' => Gender::casesWithLabels(),
            ]
        );
    }

    public function store(StoreCompleteProfileRequest $request)
    {
        $request->user()->fill([
            'name' => $request->name,
            'paternal_lastname' => $request->paternal_lastname,
            'maternal_lastname' => $request->maternal_lastname,
            'birth_date' => $request->birth_date,
            'gender' => $request->gender,
            'email' => $request->email,
            'phone' => str_replace(' ', '', (new PhoneNumber($request->phone, $request->phone_country))->formatNational()),
            'phone_country' => $request->phone_country,
        ]);

        $request->user()->save();

        return redirect()->route('home')->flashMessage('Tu perfil está completo.');
    }
}
