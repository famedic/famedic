<?php

namespace App\Services\Otp\Diagnostics;

use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use Symfony\Component\HttpFoundation\Response;

final class OtpDiagnosticContext
{
    public const HEADER_OUTCOME = 'X-OTP-Diagnostic-Outcome';

    public const HEADER_STAGE = 'X-OTP-Diagnostic-Stage';

    public const HEADER_PROVIDER_RESULT = 'X-OTP-Provider-Result';

    public const HEADER_DELIVERY_OPERATION = 'X-OTP-Delivery-Operation';

    public const HEADER_TRACE_COMPLETENESS = 'X-OTP-Trace-Completeness';

    public const HEADER_REASON = 'X-OTP-Diagnostic-Reason';

    private const OUTCOMES = [
        'challenge_created',
        'decoy',
        'replay',
        'rate_limited',
        'configuration_error',
        'pre_delivery_failure',
        'delivery_accepted',
        'delivery_rejected',
        'delivery_uncertain',
    ];

    private const STAGES = [
        'request_received',
        'eligibility',
        'intent_created',
        'challenge_created',
        'reservation_created',
        'provider_not_called',
        'provider_called',
        'provider_accepted',
        'provider_rejected',
        'observability_completed',
    ];

    private const PROVIDER_RESULTS = [
        'not_called',
        'accepted',
        'rejected',
        'uncertain',
        'not_applicable',
    ];

    private const DELIVERY_OPERATIONS = [
        'created',
        'absent',
        'unknown',
    ];

    public const REASON_USER_NOT_FOUND = 'user_not_found';

    public const REASON_PHONE_NOT_VERIFIED = 'phone_not_verified';

    public const REASON_USER_INACTIVE = 'user_inactive';

    public const REASON_CUSTOMER_MISSING = 'customer_missing';

    public const REASON_AMBIGUOUS_MATCH = 'ambiguous_match';

    public const REASON_PHONE_FORMAT_MISMATCH = 'phone_format_mismatch';

    public const REASON_UNKNOWN = 'unknown';

    public const REASONS = [
        self::REASON_USER_NOT_FOUND,
        self::REASON_PHONE_NOT_VERIFIED,
        self::REASON_USER_INACTIVE,
        self::REASON_CUSTOMER_MISSING,
        self::REASON_AMBIGUOUS_MATCH,
        self::REASON_PHONE_FORMAT_MISMATCH,
        self::REASON_UNKNOWN,
    ];

    private ?string $outcome = null;

    private string $stage = 'request_received';

    private string $providerResult = 'not_called';

    private string $deliveryOperation = 'unknown';

    private string $traceCompleteness = 'partial';

    private ?string $reason = null;

    public function reset(): void
    {
        $this->outcome = null;
        $this->stage = 'request_received';
        $this->providerResult = 'not_called';
        $this->deliveryOperation = 'unknown';
        $this->traceCompleteness = 'partial';
        $this->reason = null;
    }

    public function markRequestReceived(): void
    {
        $this->set(stage: 'request_received');
    }

    public function markEligibility(): void
    {
        $this->set(stage: 'eligibility');
    }

    public function markIntentCreated(): void
    {
        $this->set(stage: 'intent_created', deliveryOperation: 'absent');
    }

    public function markChallengeCreated(): void
    {
        $this->set(outcome: 'challenge_created', stage: 'challenge_created', deliveryOperation: 'absent');
    }

    public function markRealChallengeProviderNotApplicable(): void
    {
        $this->set(
            outcome: 'challenge_created',
            stage: 'challenge_created',
            providerResult: 'not_applicable',
            deliveryOperation: 'absent',
            traceCompleteness: 'complete',
        );
    }

    public function markReservationCreated(): void
    {
        $this->set(stage: 'reservation_created');
    }

    public function markProviderNotCalled(?string $outcome = null): void
    {
        $this->set(
            outcome: $outcome,
            stage: 'provider_not_called',
            providerResult: 'not_called',
            deliveryOperation: $this->deliveryOperation === 'unknown' ? 'absent' : null,
        );
    }

    public function markProviderCalled(): void
    {
        $this->set(stage: 'provider_called');
    }

    public function markDeliveryOperationCreated(): void
    {
        $this->set(deliveryOperation: 'created');
    }

    public function markDeliveryAccepted(): void
    {
        $this->set(
            outcome: 'delivery_accepted',
            stage: 'provider_accepted',
            providerResult: 'accepted',
            deliveryOperation: 'created',
            traceCompleteness: 'complete',
        );
    }

    public function markDeliveryRejected(): void
    {
        $this->set(
            outcome: 'delivery_rejected',
            stage: 'provider_rejected',
            providerResult: 'rejected',
            deliveryOperation: 'created',
            traceCompleteness: 'complete',
        );
    }

    public function markDeliveryUncertain(): void
    {
        $this->set(
            outcome: 'delivery_uncertain',
            stage: 'provider_called',
            providerResult: 'uncertain',
            traceCompleteness: 'partial',
        );
    }

    public function markObservabilityCompleted(): void
    {
        $this->set(stage: 'observability_completed');
    }

    public function markDecoy(?string $reason = null): void
    {
        $this->set(
            outcome: 'decoy',
            stage: 'provider_not_called',
            providerResult: 'not_called',
            deliveryOperation: 'absent',
            traceCompleteness: 'complete',
            reason: $reason,
        );
    }

    public function markDecoyReason(string $reason): void
    {
        $this->set(reason: $reason);
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }

    public function markReplay(): void
    {
        $this->set(
            outcome: 'replay',
            stage: 'provider_not_called',
            providerResult: 'not_called',
            deliveryOperation: 'unknown',
            traceCompleteness: 'partial',
        );
    }

    public function markRateLimited(): void
    {
        $this->set(
            outcome: 'rate_limited',
            stage: 'provider_not_called',
            providerResult: 'not_called',
            deliveryOperation: 'absent',
            traceCompleteness: 'complete',
        );
    }

    public function markConfigurationError(): void
    {
        $this->set(
            outcome: 'configuration_error',
            stage: 'provider_not_called',
            providerResult: 'not_applicable',
            deliveryOperation: 'absent',
            traceCompleteness: 'complete',
        );
    }

    public function markPreDeliveryFailure(): void
    {
        $this->set(
            outcome: 'pre_delivery_failure',
            stage: 'provider_not_called',
            providerResult: 'not_called',
            deliveryOperation: 'absent',
            traceCompleteness: 'partial',
        );
    }

    public function markFromDeliveryResult(OtpDeliveryResultClass $resultClass): void
    {
        match (true) {
            $resultClass === OtpDeliveryResultClass::Accepted,
            $resultClass === OtpDeliveryResultClass::FallbackAccepted => $this->markDeliveryAccepted(),
            $resultClass === OtpDeliveryResultClass::Timeout,
            $resultClass === OtpDeliveryResultClass::TransportError,
            $resultClass === OtpDeliveryResultClass::InvalidProviderResponse => $this->markDeliveryUncertain(),
            default => $this->markDeliveryRejected(),
        };
    }

    /**
     * @return array<string, string>
     */
    public function headersFor(Response $response): array
    {
        if ($response->getStatusCode() === 429 && $this->outcome === null) {
            $this->markRateLimited();
        }

        $outcome = $this->outcome ?? 'pre_delivery_failure';

        $headers = [
            self::HEADER_OUTCOME => $this->closed($outcome, self::OUTCOMES, 'pre_delivery_failure'),
            self::HEADER_STAGE => $this->closed($this->stage, self::STAGES, 'request_received'),
            self::HEADER_PROVIDER_RESULT => $this->closed($this->providerResult, self::PROVIDER_RESULTS, 'not_called'),
            self::HEADER_DELIVERY_OPERATION => $this->closed($this->deliveryOperation, self::DELIVERY_OPERATIONS, 'unknown'),
            self::HEADER_TRACE_COMPLETENESS => $this->closed($this->traceCompleteness, ['complete', 'partial'], 'partial'),
        ];

        if ($this->reason !== null) {
            $headers[self::HEADER_REASON] = $this->closed($this->reason, self::REASONS, self::REASON_UNKNOWN);
        }

        return $headers;
    }

    public function set(
        ?string $outcome = null,
        ?string $stage = null,
        ?string $providerResult = null,
        ?string $deliveryOperation = null,
        ?string $traceCompleteness = null,
        ?string $reason = null,
    ): void {
        if ($outcome !== null && in_array($outcome, self::OUTCOMES, true)) {
            $this->outcome = $outcome;
        }

        if ($stage !== null && in_array($stage, self::STAGES, true)) {
            $this->stage = $stage;
        }

        if ($providerResult !== null && in_array($providerResult, self::PROVIDER_RESULTS, true)) {
            $this->providerResult = $providerResult;
        }

        if ($deliveryOperation !== null && in_array($deliveryOperation, self::DELIVERY_OPERATIONS, true)) {
            $this->deliveryOperation = $deliveryOperation;
        }

        if ($traceCompleteness !== null && in_array($traceCompleteness, ['complete', 'partial'], true)) {
            $this->traceCompleteness = $traceCompleteness;
        }

        if ($reason !== null && in_array($reason, self::REASONS, true)) {
            $this->reason = $reason;
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function closed(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
