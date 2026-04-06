<?php

namespace App\Http\Middleware;

use App\Support\LabResultsOtpTrustSession;
use Closure;
use Illuminate\Http\Request;

class EnsureLabResultsOtpVerified
{
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

        if (! LabResultsOtpTrustSession::isValid($request, (int) $purchaseId)) {
            return abort(403);
        }

        return $next($request);
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
