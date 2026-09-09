<?php

namespace App\Services\Otp\Delivery;

/**
 * Determines when a single deterministic fallback without per-message callback is safe.
 *
 * Vonage SMS API statuses documented as pre-queue validation failures (message not accepted):
 * @see https://developer.vonage.com/api/sms#delivery-receipt
 *   2 — Missing parameter
 *   3 — Invalid parameter
 *
 * Status 2/3 alone are NOT sufficient: other invalid params share these codes.
 * Fallback requires explicit callback attribution in Vonage error-text.
 */
final class VonageSmsCallbackFallbackPolicy
{
    /** @var list<int> */
    private const PRE_QUEUE_REJECTION_STATUSES = [2, 3];

    public static function allowsFallbackWithoutCallback(
        VonageSmsCallbackApplyResult $callback,
        VonageSmsSendParseResult $parsed,
    ): bool {
        if (! $callback->applied) {
            return false;
        }

        if ($parsed->accepted || ! $parsed->interpretable) {
            return false;
        }

        if ($parsed->vonageStatus === null || $parsed->vonageStatus === 0) {
            return false;
        }

        if (! in_array($parsed->vonageStatus, self::PRE_QUEUE_REJECTION_STATUSES, true)) {
            return false;
        }

        return self::isCallbackAttributedReject($parsed);
    }

    public static function isCallbackAttributedReject(VonageSmsSendParseResult $parsed): bool
    {
        $errorText = strtolower(trim((string) ($parsed->errorText ?? '')));

        return $errorText !== '' && str_contains($errorText, 'callback');
    }
}
