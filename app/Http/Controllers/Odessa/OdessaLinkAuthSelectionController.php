<?php

namespace App\Http\Controllers\Odessa;

use App\Actions\Odessa\DecodeOdessaTokenAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OdessaLinkAuthSelectionController extends Controller
{
    function index(Request $request, string $odessaToken, DecodeOdessaTokenAction $decodeOdessaTokenAction)
    {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);
        } catch (\Exception $e) {
            report($e);
            return redirect()->route('login');
        }

        return Inertia::render('Auth/OdessaLinkAuthSelection', [
            'secondsLeft' => (int)floor($odessaTokenData->expiration->diffInSeconds(now(), true)),
        ]);
    }
}
