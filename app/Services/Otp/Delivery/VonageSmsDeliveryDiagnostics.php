<?php

namespace App\Services\Otp\Delivery;

use Illuminate\Support\Facades\Log;

final class VonageSmsDeliveryDiagnostics
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function log(string $stage, array $context = []): void
    {
        Log::info('vonage_sms_otp_delivery', array_merge([
            'stage' => $stage,
        ], self::sanitize($context)));
    }

    public static function sanitizeErrorText(?string $errorText): ?string
    {
        if ($errorText === null || trim($errorText) === '') {
            return null;
        }

        $sanitized = preg_replace('/https?:\/\/\S+/i', '[URL_REDACTED]', trim($errorText));
        $sanitized = preg_replace('/\b[a-f0-9]{32,}\b/i', '[TOKEN_REDACTED]', (string) $sanitized);

        return mb_substr((string) $sanitized, 0, 120);
    }

    /**
     * @param  array<string, scalar|null>  $context
     * @return array<string, scalar|null>
     */
    private static function sanitize(array $context): array
    {
        $allowed = [
            'correlation_id',
            'challenge_public_id',
            'exception_class',
            'failure_stage',
            'vonage_status',
            'vonage_error_code',
            'vonage_error_text',
            'http_status_class',
            'callback_applied',
            'callback_skip_reason',
            'callback_host',
            'callback_url_length',
            'message_id_prefix',
        ];

        $filtered = array_intersect_key($context, array_flip($allowed));

        if (isset($filtered['vonage_error_text']) && is_string($filtered['vonage_error_text'])) {
            $filtered['vonage_error_text'] = self::sanitizeErrorText($filtered['vonage_error_text']);
        }

        return $filtered;
    }
}
