<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureLabResultsOtpVerified
{
    private const SESSION_MINUTES = 15;

    public function handle(Request $request, Closure $next)
    {
        $type = (string) ($request->route('type') ?? '');
        if ($type !== '' && $type !== 'purchase') {
            return $next($request);
        }

        $purchaseId = $this->resolvePurchaseId($request);
        if (! $purchaseId) {
            return abort(403);
        }

        $verifiedAt = $request->session()->get($this->sessionKey((int) $purchaseId));
        if (! $verifiedAt) {
            return abort(403);
        }

        $verifiedAtTs = is_numeric($verifiedAt) ? (int) $verifiedAt : strtotime((string) $verifiedAt);
        if (! $verifiedAtTs) {
            return abort(403);
        }

        $expiresAtTs = $verifiedAtTs + (self::SESSION_MINUTES * 60);
        if (time() >= $expiresAtTs) {
            return abort(403);
        }

        return $next($request);
    }

    private function sessionKey(int $purchaseId): string
    {
        return "otp_verified_at:lab_results:purchase:{$purchaseId}";
    }

    private function resolvePurchaseId(Request $request): ?int
    {
        // laboratory-purchases.{results,results.automatic-fetch}
        $purchase = $request->route('laboratory_purchase') ?? $request->route('laboratoryPurchase') ?? null;
        if ($purchase) {
            return (int) (is_object($purchase) ? ($purchase->id ?? null) : $purchase);
        }

        // laboratory-results/{type}/{id}
        $type = (string) ($request->route('type') ?? '');
        if ($type === 'purchase') {
            $id = $request->route('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }
}
