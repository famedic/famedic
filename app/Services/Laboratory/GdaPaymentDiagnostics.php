<?php

namespace App\Services\Laboratory;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Diagnostic failure must never change the financial outcome. Call with controlled codes/context. */
final class GdaPaymentDiagnostics
{
    public static function record(string $level, string $code, array $context): void
    {
        try {
            Log::log(in_array($level, ['warning', 'error'], true) ? $level : 'warning', $code, $context);
        } catch (Throwable) {
            // The durable purchase/payment state remains the recovery source of truth.
        }
    }
}
