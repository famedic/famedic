<?php

namespace App\Http\Controllers\Odessa;

use App\Actions\Odessa\DecodeOdessaTokenAction;
use App\Actions\Odessa\SendProperAccountLinkingAction;
use App\Actions\Users\GenerateLoginLinkForUserAction;
use App\DTOs\OdessaTokenData;
use App\Exceptions\InexistentSupposedlyAlreadyLinkedOdessaAfiliateMemberException;
use App\Exceptions\LinkedOdessaAfiliateMemberMismatchException;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Http\Controllers\Controller;
use Exception;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OdessaController extends Controller
{
    public function index(
        Request $request,
        string $odessa_token,
        DecodeOdessaTokenAction $decodeOdessaTokenAction,
        GenerateLoginLinkForUserAction $generateLoginLinkForUserAction,
        SendProperAccountLinkingAction $sendProperAccountLinkingAction
    ) {
        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessa_token);

            // Scenario 1: Unregistered and Unlinked User
            if (
                !$odessaTokenData->hasLinkedOdessaAfiliateAccount &&
                !$odessaTokenData->odessaAfiliateAccount
            ) {
                // Redirect the user to the Odessa registration page with the token as a parameter
                return redirect()->route('odessa-link-auth-selection.index', ['odessa_token' => $odessa_token]);
            }

            // Scenario 2: Registered but Unlinked User
            if (
                !$odessaTokenData->hasLinkedOdessaAfiliateAccount &&
                $odessaTokenData->odessaAfiliateAccount
            ) {
                // Initiate the account linking process with verification and show the login form after the account is linked
                ($sendProperAccountLinkingAction)($odessaTokenData->odessaAfiliateAccount, $odessaTokenData);
                return $this->showAutoLoginForm($odessaTokenData, $generateLoginLinkForUserAction);
            }

            // Scenario 3: Properly Linked User
            if (
                $odessaTokenData->hasLinkedOdessaAfiliateAccount &&
                $odessaTokenData->linkedOdessaAfiliateAccount &&
                $odessaTokenData->odessaAfiliateAccountId == $odessaTokenData->linkedOdessaAfiliateAccount->id
            ) {
                // Show the login form
                return $this->showAutoLoginForm($odessaTokenData, $generateLoginLinkForUserAction);
            }

            // Scenario 4: Linked User Mismatch
            if (
                $odessaTokenData->hasLinkedOdessaAfiliateAccount &&
                $odessaTokenData->linkedOdessaAfiliateAccount &&
                $odessaTokenData->odessaAfiliateAccountId != $odessaTokenData->linkedOdessaAfiliateAccount->id
            ) {
                // Flag the issue for manual inspection
                throw new LinkedOdessaAfiliateMemberMismatchException();
            }

            // Scenario 5: Inexistent Supposedly Already Linked Odessa Afiliate Member
            if (
                $odessaTokenData->hasLinkedOdessaAfiliateAccount &&
                !$odessaTokenData->linkedOdessaAfiliateAccount
            ) {
                throw new InexistentSupposedlyAlreadyLinkedOdessaAfiliateMemberException();
            }

            // Scenario 6: Unhandled Exception
            throw new Exception('Unhandled exception');
        } catch (InexistentSupposedlyAlreadyLinkedOdessaAfiliateMemberException $e) {
            report($e);
            return redirect()->route('login');
        } catch (LinkedOdessaAfiliateMemberMismatchException $e) {
            report($e);
            return redirect()->route('login');
        } catch (OdessaAfiliateMemberAlreadyLinkedException $e) {
            report($e);
            return redirect()->route('login');
        } catch (ExpiredException $e) {
            return redirect()->route('login');
        } catch (\Exception $e) {
            report($e);
            return redirect()->route('login');
        }
    }

    function showAutoLoginForm(OdessaTokenData $odessaTokenData, GenerateLoginLinkForUserAction $generateLoginLinkForUserAction)
    {
        return Inertia::render(
            'Auth/OdessaAutoLogin',
            [
                'loginUrl' => ($generateLoginLinkForUserAction)(
                    $odessaTokenData
                        ->odessaAfiliateAccount
                        ->customer
                        ->user
                )
            ]
        );
    }
}
