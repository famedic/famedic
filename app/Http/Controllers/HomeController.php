<?php

namespace App\Http\Controllers;

use App\Actions\Users\GenerateInvitationUrlAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __invoke(Request $request, GenerateInvitationUrlAction $generateInvitationUrlAction)
    {
        $invitationUrl = null;
        $user = null;

        if ($request->user()) {
            $invitationUrl = $generateInvitationUrlAction($request->user());
            $user = $request->user(); // Obtener el usuario autenticado
        }

        return Inertia::render('Home', [
            'invitationUrl' => $invitationUrl,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // Agrega cualquier otro campo que necesites del usuario
                ] : null,
            ],
        ]);
    }
}