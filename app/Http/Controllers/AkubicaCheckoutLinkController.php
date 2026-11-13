<?php

namespace App\Http\Controllers;

use App\Models\AkubicaCheckoutLink;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AkubicaCheckoutLinkController extends Controller
{
    public function __invoke(
        Request $request,
        ?string $token,
        CheckoutPreparation $checkoutPreparation,
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

    private function legacyPaymentLink(
        Request $request,
        string $token,
        CheckoutPreparation $checkoutPreparation,
    ): RedirectResponse {
        $link = AkubicaCheckoutLink::findByPlainToken($token);

        if (! $link) {
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
