<?php

namespace App\Services\Otp\Delivery;

use Illuminate\Support\Facades\Log;

final class OtpDeliveryObservability
{
    /** @param array<string, scalar|null> $dims */
    public function emit(string $event, array $dims): void
    {
        $allowed = [
            'environment', 'purpose', 'channel', 'provider_alias', 'result_class',
            'attempt_number', 'http_status_class', 'application_error_code', 'duration_bucket',
            'correlation_id', 'otp_challenge_public_id',
        ];
        $context = array_intersect_key($dims, array_flip($allowed));
        $context['environment'] ??= app()->environment();
        Log::info($event, $context);
    }

    public function durationBucket(int $milliseconds): string
    {
        return match (true) {
            $milliseconds < 100 => '0-100',
            $milliseconds < 250 => '100-250',
            $milliseconds < 500 => '250-500',
            $milliseconds < 1000 => '500-1000',
            default => '1000+',
        };
    }
}
