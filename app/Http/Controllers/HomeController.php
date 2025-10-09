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

        if ($request->user()) {
            $invitationUrl = $generateInvitationUrlAction($request->user());
        }

        return Inertia::render('Home', [
            'invitationUrl' => $invitationUrl,
        ]);
    }
}
