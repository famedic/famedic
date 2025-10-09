<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Odessa\DecodeOdessaTokenAction;
use App\Actions\Odessa\RegisterOdessaAfiliateCustomerAction;
use App\Enums\Gender;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Exceptions\OdessaAfiliateMemberMismatchException;
use App\Exceptions\OdessaIdAlreadyLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Tracking\CompleteRegistration;
use App\Services\Tracking\Tracking;
use Carbon\Carbon;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OdessaRegisterController extends Controller
{
    public function index(Request $request, string $odessaToken, DecodeOdessaTokenAction $decodeOdessaTokenAction)
    {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            if (!$odessaTokenData
                ->odessaAfiliateAccount
                ?->customer
                ?->user) {
                return Inertia::render(
                    'Auth/Register',
                    [
                        'genders' => Gender::casesWithLabels(),
                        'secondsLeft' => (int)floor($odessaTokenData->expiration->diffInSeconds(now(), true)),
                        'odessaToken' => $odessaToken,
                    ]
                );
            }
            return redirect()->route('login');
        } catch (ExpiredException $e) {
            return redirect()->route('login');
        } catch (\Exception $e) {
            report($e);
            return redirect()->route('login');
        }
    }

    public function store(
        RegisterRequest $request,
        string $odessaToken,
        DecodeOdessaTokenAction $decodeOdessaTokenAction,
        RegisterOdessaAfiliateCustomerAction $registerOdessaAfiliateMemberAction
    ) {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            $odessaAfiliateAccount = ($registerOdessaAfiliateMemberAction)(
                name: $request->name,
                paternalLastname: $request->paternal_lastname,
                maternalLastname: $request->maternal_lastname,
                birthDate: Carbon::parse($request->birth_date),
                gender: Gender::from($request->gender),
                phone: $request->phone,
                phoneCountry: $request->phone_country,
                email: $request->email,
                password: $request->password,
                odessaTokenData: $odessaTokenData
            );

            Auth::login($odessaAfiliateAccount->customer->user);

            CompleteRegistration::track();

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
