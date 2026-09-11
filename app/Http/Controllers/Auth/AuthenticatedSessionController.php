<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Marketing\AttachMarketingCampaignAttributionToCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(
        LoginRequest $request,
        AttachMarketingCampaignAttributionToCustomerAction $attachMarketingAttribution,
    ): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $this->attachMarketingAttribution($request, $attachMarketingAttribution, 'web_login');

        return redirect()->intended(route('home', absolute: false))->flashMessage('¡Bienvenido a Famedic!');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function attachMarketingAttribution(
        Request $request,
        AttachMarketingCampaignAttributionToCustomerAction $attachMarketingAttribution,
        string $stage,
    ): void {
        try {
            $user = $request->user();

            if ($user !== null) {
                $attachMarketingAttribution($request, $user, $stage);
            }
        } catch (\Throwable $exception) {
            Log::warning('marketing_campaign_attribution_attach_failed', [
                'stage' => $stage,
                'exception' => $exception::class,
                'message' => 'Marketing campaign attribution attach failed.',
            ]);
        }
    }
}
