<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\ResolveLaboratoryCheckoutResumeLinkAction;
use App\DTOs\Laboratories\LaboratoryCheckoutResumeResolution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LaboratoryCheckoutResumeController extends Controller
{
    public function __invoke(
        string $token,
        Request $request,
        ResolveLaboratoryCheckoutResumeLinkAction $resolveResumeLink,
    ): RedirectResponse {
        $resolution = $resolveResumeLink($token, $request->user());

        if ($resolution->status === LaboratoryCheckoutResumeResolution::UNAUTHENTICATED) {
            return redirect()->guest(route('login'));
        }

        if ($resolution->status === LaboratoryCheckoutResumeResolution::FORBIDDEN) {
            abort(403);
        }

        if (in_array($resolution->status, [
            LaboratoryCheckoutResumeResolution::READY,
            LaboratoryCheckoutResumeResolution::COMPLETED,
        ], true)) {
            return redirect()->to($resolution->redirectUrl);
        }

        if ($resolution->status === LaboratoryCheckoutResumeResolution::EMPTY_CART && $resolution->redirectUrl) {
            return redirect()->to($resolution->redirectUrl)
                ->with('checkout_resume_error', 'El carrito ya no esta disponible para continuar.');
        }

        return redirect()->route('laboratory-brand-selection')
            ->with('checkout_resume_error', 'El enlace para continuar el checkout no es valido o ya expiro.');
    }
}
