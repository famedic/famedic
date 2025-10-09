<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterRegularCustomerAction;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Tracking\CompleteRegistration;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function createFromInvitation(User $user): Response
    {
        return Inertia::render('Auth/Register', [
            'genders' => Gender::casesWithLabels(),
            'inviter' => [
                'id' => $user->id,
                'name' => $user->full_name,
            ],
        ]);
    }

    public function store(RegisterRequest $request, RegisterRegularCustomerAction $action): RedirectResponse
    {
        $regularAccount = $action(
            name: $request->name,
            paternalLastname: $request->paternal_lastname,
            maternalLastname: $request->maternal_lastname,
            birthDate: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            phone: $request->phone,
            phoneCountry: $request->phone_country,
            email: $request->email,
            password: $request->password,
            referrerUserId: $request->referrer_id,
        );

        Auth::login($regularAccount->customer->user);

        CompleteRegistration::track();

        return to_route('home')
            ->flashMessage('Registro existoso. ¡Bienvenido a Famedic!');
    }
}
