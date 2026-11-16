<?php

namespace App\Http\Controllers;

use App\Models\AkubicaCheckoutLink;
use App\Services\Api\V1\Continuity\MedicalCheckoutContinuity;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AkubicaCheckoutLinkController extends Controller
{
    public function __invoke(
        Request $request,
        CheckoutPreparation $checkoutPreparation,
        ?string $token = null,
    ): RedirectResponse {
        if ($request->routeIs('akubica.checkout.continue', 'akubica.checkout.continue.resume')) {
            return $this->continueToCheckout($request, $token, $checkoutPreparation);
        }

        return $this->legacyPaymentLink($request, (string) $token, $checkoutPreparation);
    }

    private function continueToCheckout(
        Request $request,
        ?string $token,
        CheckoutPreparation $checkoutPreparation,
    ): RedirectResponse {
        if ($request->routeIs('akubica.checkout.continue.resume')) {
            $token = $request->session()->pull('akubica.checkout.continue_token');
        }

        if (! is_string($token) || $token === '') {
            return redirect()->route('login')->with('error', 'La liga de checkout no es válida.');
        }

        $link = AkubicaCheckoutLink::findByPlainToken($token);

        if ($link && ($link->metadata['purpose'] ?? null) === MedicalCheckoutContinuity::PURPOSE) {
            return $this->continueToMedicalCheckout($request, $link, $token);
        }

        if (! $link || ($link->metadata['purpose'] ?? null) !== 'akubica_continuity') {
            return redirect()->route('login')->with('error', 'La liga de checkout no es válida.');
        }

        if ($link->isExpired()) {
            return redirect()->route('login')->with('error', 'La liga de checkout ha expirado.');
        }

        if (! Auth::guard('web')->check()) {
            $request->session()->put('akubica.checkout.continue_token', $token);

            return redirect()->route('login');
        }

        $customer = $link->customer;
        $owner = $customer?->user;

        if (! $owner || (int) Auth::id() !== (int) $owner->getKey()) {
            return redirect()->route('login')->with('error', 'La liga de checkout no corresponde a la sesión activa.');
        }

        $brand = $link->laboratory_brand;
        $readiness = $checkoutPreparation->validatePaymentLinkReadiness($customer, $brand);

        if (isset($readiness['error'])) {
            $message = match ($readiness['error']) {
                'EMPTY_CART' => 'El carrito ya no tiene estudios.',
                'CHECKOUT_NOT_READY' => 'El checkout ya no está listo para continuar.',
                'APPOINTMENT_REQUIRED' => 'Este carrito requiere una cita antes de continuar al pago.',
                default => 'No se pudo continuar al checkout.',
            };

            return redirect()->route('login')->with('error', $message);
        }

        if (! $link->claim()) {
            return redirect()->route('login')->with('error', 'La liga de checkout ya fue utilizada.');
        }

        /** @var \App\Models\LaboratoryCheckoutDraft $draft */
        $draft = $readiness['draft'];

        return redirect()->route('laboratory.checkout', [
            'laboratory_brand' => $brand->value,
            ...$checkoutPreparation->checkoutRedirectQuery($draft),
        ]);
    }

    /**
     * RC2 B24 (D2): opens the FAMEDIC membership checkout for the bound user/cart. Single use, TTL,
     * web session of the owner required (never logs anyone in), no payment and no activation here.
     */
    private function continueToMedicalCheckout(Request $request, AkubicaCheckoutLink $link, string $token): RedirectResponse
    {
        if (! MedicalCheckoutContinuity::enabled()) {
            return redirect()->route('login')->with('error', 'La liga de checkout no es válida.');
        }

        if ($link->used_at !== null) {
            return redirect()->route('login')->with('error', 'La liga de checkout ya fue utilizada.');
        }

        if ($link->isExpired()) {
            return redirect()->route('login')->with('error', 'La liga de checkout ha expirado.');
        }

        if (! Auth::guard('web')->check()) {
            $request->session()->put('akubica.checkout.continue_token', $token);

            return redirect()->route('login');
        }

        $owner = $link->customer?->user;

        if (! $owner || (int) Auth::id() !== (int) $owner->getKey()) {
            Log::warning('akubica_medical_continuity_rejected', ['link_id' => $link->id, 'reason' => 'session_mismatch']);

            return redirect()->route('login')->with('error', 'La liga de checkout no corresponde a la sesión activa.');
        }

        $failure = app(MedicalCheckoutContinuity::class)->bindingFailure($link);

        if ($failure !== null) {
            Log::info('akubica_medical_continuity_rejected', ['link_id' => $link->id, 'reason' => 'cart_binding']);

            return redirect()->route('login')->with('error', $failure);
        }

        if (! $link->claim()) {
            return redirect()->route('login')->with('error', 'La liga de checkout ya fue utilizada.');
        }

        Log::info('akubica_medical_continuity_redeemed', ['link_id' => $link->id, 'customer_id' => $link->customer_id]);

        return redirect()->route('medical-attention.checkout');
    }

    private function legacyPaymentLink(
        Request $request,
        string $token,
        CheckoutPreparation $checkoutPreparation,
    ): RedirectResponse {
        $link = AkubicaCheckoutLink::findByPlainToken($token);

        // Continuity links never authenticate anyone: they are not accepted on the legacy payment-link route.
        if (! $link || in_array($link->metadata['purpose'] ?? null, ['akubica_continuity', MedicalCheckoutContinuity::PURPOSE], true)) {
            return redirect()->route('login')->with('error', 'La liga de pago no es válida.');
        }

        if ($link->isExpired()) {
            return redirect()->route('login')->with('error', 'La liga de pago ha expirado.');
        }

        $customer = $link->customer;
        $brand = $link->laboratory_brand;
        $user = $customer->user;

        if (! $user) {
            return redirect()->route('login')->with('error', 'No se pudo continuar al checkout.');
        }

        $readiness = $checkoutPreparation->validatePaymentLinkReadiness($customer, $brand);

        if (isset($readiness['error'])) {
            $message = match ($readiness['error']) {
                'EMPTY_CART' => 'El carrito ya no tiene estudios.',
                'CHECKOUT_NOT_READY' => 'El checkout ya no está listo para continuar.',
                'APPOINTMENT_REQUIRED' => 'Este carrito requiere una cita antes de continuar al pago.',
                default => 'No se pudo continuar al checkout.',
            };

            return redirect()->route('login')->with('error', $message);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $link->markUsed();

        /** @var \App\Models\LaboratoryCheckoutDraft $draft */
        $draft = $readiness['draft'];

        return redirect()->route('laboratory.checkout', [
            'laboratory_brand' => $brand->value,
            ...$checkoutPreparation->checkoutRedirectQuery($draft),
        ]);
    }
}
