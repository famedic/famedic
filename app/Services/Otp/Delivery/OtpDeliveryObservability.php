<?php

namespace App\Services\Otp\Delivery;

use App\Services\Otp\Monitoring\OtpMovementRecorder;
use Illuminate\Support\Facades\Log;

final class OtpDeliveryObservability
{
    public function __construct(
        private readonly OtpMovementRecorder $movementRecorder,
    ) {}

    /** @param array<string, scalar|null> $dims */
    public function emit(string $event, array $dims): void
    {
        try {
            $allowed = [
                'environment', 'purpose', 'channel', 'provider_alias', 'result_class',
                'attempt_number', 'http_status_class', 'application_error_code', 'duration_bucket',
                'correlation_id', 'otp_challenge_public_id',
            ];
            $context = array_intersect_key($dims, array_flip($allowed));
            $context['environment'] ??= app()->environment();
            Log::info($event, $context);
            $this->movementRecorder->recordDeliveryObserved($event, $context);
        } catch (\Throwable $e) {
            VonageSmsDeliveryDiagnostics::log('observability_failed', [
                'correlation_id' => is_string($dims['correlation_id'] ?? null) ? $dims['correlation_id'] : null,
                'challenge_public_id' => is_string($dims['otp_challenge_public_id'] ?? null) ? $dims['otp_challenge_public_id'] : null,
                'failure_stage' => 'observability_emit',
                'exception_class' => $e::class,
                'exception_message' => app()->environment('local')
                    ? VonageSmsDeliveryDiagnostics::sanitizeErrorText($e->getMessage())
                    : null,
                'final_result_class' => is_string($dims['result_class'] ?? null) ? $dims['result_class'] : null,
            ]);
        }
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
