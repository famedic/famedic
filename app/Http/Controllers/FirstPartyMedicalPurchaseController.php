<?php

namespace App\Http\Controllers;

use App\Services\Api\V1\Benefits\BenefitErrorResponse;
use App\Services\MedicalCheckout\FirstPartyMedicalPurchase;
use Illuminate\Http\Request;

final class FirstPartyMedicalPurchaseController
{
    public function __construct(private FirstPartyMedicalPurchase $checkout) {}

    private function respond(Request $r, callable $op, bool $page = false)
    {
        try {
            $data = $op();
            $response = $page ? response()->view('medical-attention.benefit-checkout', ['review' => $data]) : response()->json(['success' => true, 'data' => $data]);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $response = response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN']], 403);
        } catch (\Throwable $e) {
            $response = BenefitErrorResponse::make($e);
        }

        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }

    public function review(Request $r)
    {
        return $this->respond($r, fn () => $this->checkout->review($r), true);
    }

    public function confirm(Request $r)
    {
        return $this->respond($r, fn () => $this->checkout->confirm($r));
    }

    public function status(Request $r, string $checkout_ref)
    {
        return $this->respond($r, fn () => $this->checkout->status($r, $checkout_ref));
    }

    public function action(Request $r, string $checkout_ref, string $action)
    {
        return $this->respond($r, fn () => $this->checkout->action($r, $checkout_ref, $action));
    }
}
