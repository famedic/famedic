<?php

namespace App\Http\Controllers\Odessa;

use App\Actions\Odessa\DecodeOdessaTokenAction;
use App\Actions\Odessa\UpgradeRegularCustomerToOdessaAfiliateAction;
use App\Http\Requests\Odessa\StoreOdessaUpgradeRequest;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Exceptions\OdessaAfiliateMemberMismatchException;
use App\Exceptions\OdessaIdAlreadyLinkedException;
use App\Http\Controllers\Controller;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OdessaUpgradeController extends Controller
{
    public function index(Request $request, string $odessaToken, DecodeOdessaTokenAction $decodeOdessaTokenAction)
    {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            return Inertia::render('Auth/OdessaUpgradeConfirm', [
                'secondsLeft' => (int)floor($odessaTokenData->expiration->diffInSeconds(now(), true)),
                'odessaToken' => $odessaToken,
                'canUpgrade' => $request->user()->customer->has_regular_account,
            ]);
        } catch (ExpiredException $e) {
            return redirect()->route('login');
        } catch (\Exception $e) {
            report($e);
            return redirect()->route('login');
        }
    }

    public function store(
        StoreOdessaUpgradeRequest $request,
        string $odessaToken,
        DecodeOdessaTokenAction $decodeOdessaTokenAction,
        UpgradeRegularCustomerToOdessaAfiliateAction $upgradeAction
    ) {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            ($upgradeAction)(Auth::user()->customer, $odessaTokenData);

            return to_route('home')
                ->flashMessage('Registro existoso. ¡Bienvenido a Famedic!');
        } catch (ExpiredException $e) {
            DB::rollBack();
            return redirect()->route('login');
        } catch (OdessaAfiliateMemberAlreadyLinkedException $e) {
            throw $e;
            return redirect()->back()->flashMessage('Hay un problema con tu cuenta. Ya ha sido reportado. Te recomendamos ponerte en contacto con tu administrador, para tener solución lo más pronto posible.', 'error');
        } catch (OdessaAfiliateMemberMismatchException $e) {
            throw $e;
            return redirect()->back()->flashMessage('Hay un problema con tu cuenta. Ya ha sido reportado. Te recomendamos ponerte en contacto con tu administrador, para tener solución lo más pronto posible.', 'error');
        } catch (OdessaIdAlreadyLinkedException $e) {
            throw $e;
            return redirect()->back()->flashMessage('Hay un problema con tu cuenta. Ya ha sido reportado. Te recomendamos ponerte en contacto con tu administrador, para tener solución lo más pronto posible.', 'error');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->route('login');
        }
    }
}
