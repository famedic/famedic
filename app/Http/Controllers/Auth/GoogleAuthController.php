<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Actions\Register\RegisterRegularCustomerAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }


    public function handleGoogleCallback(
        Request $request,
        RegisterRegularCustomerAction $action,
        AttachMarketingCampaignAttributionToCustomerAction $attachMarketingAttribution,
    )
    {
        $googleUser = Socialite::driver('google')->user();

        $emailUser = User::where('email', $googleUser->email)->first();

        $authUser = Auth::user();

        if ($emailUser) {
            if ($authUser) {
                if ($authUser->email == $googleUser->email) {
                    return redirect()->route('home');
                }

                return redirect()->route('login');
            }

            Auth::login($emailUser);

            $this->attachMarketingAttribution($request, $emailUser, $attachMarketingAttribution, 'google_login');

            return redirect()->route('home')->flashMessage('Inicio de sesión exitoso.');
        }

        if ($authUser) {
            return redirect()->route('home');
        }

        $regularAccount = $action(
            email: $googleUser->email,
        );

        Auth::login($regularAccount->customer->user);

        $this->attachMarketingAttribution(
            $request,
            $regularAccount->customer->user,
            $attachMarketingAttribution,
            'google_register',
        );

        return redirect()->route('home')->flashMessage('Inicio de sesión exitoso.');
    }

    private function attachMarketingAttribution(
        Request $request,
        User $user,
        AttachMarketingCampaignAttributionToCustomerAction $attachMarketingAttribution,
        string $stage,
    ): void {
        try {
            $attachMarketingAttribution($request, $user, $stage);
        } catch (\Throwable $exception) {
            Log::warning('marketing_campaign_attribution_attach_failed', [
                'stage' => $stage,
                'exception' => $exception::class,
                'message' => 'Marketing campaign attribution attach failed.',
            ]);
        }
    }
}
