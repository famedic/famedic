<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class PasswordlessAuthenticationController extends Controller
{
    public function __invoke(
        Request $request,
        AttachMarketingCampaignAttributionToCustomerAction $attachMarketingAttribution,
    )
    {
        $user = User::findOrFail($request->user);

        if (!URL::hasValidSignature($request)) {
            return redirect('/login');
        }

        Auth::login($user);

        $request->session()->regenerate();

        $this->attachMarketingAttribution($request, $user, $attachMarketingAttribution, 'passwordless_login');

        $requestUrl = $request->redirect;

        if (URL::isValidUrl($requestUrl)) {
            return redirect($requestUrl)->flashMessage('¡Bienvenido a Famedic!');
        }

        return redirect('/home')->flashMessage('¡Bienvenido a Famedic!');
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
