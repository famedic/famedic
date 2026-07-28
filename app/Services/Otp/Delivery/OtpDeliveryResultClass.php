<?php

namespace App\Services\Otp\Delivery;

enum OtpDeliveryResultClass: string
{
    case Accepted = 'accepted';
    case Timeout = 'timeout';
    case TransportError = 'transport_error';
    case RateLimitedByProvider = 'rate_limited_by_provider';
    case ProviderTemporaryFailure = 'provider_temporary_failure';
    case ProviderPermanentFailure = 'provider_permanent_failure';
    case InvalidProviderResponse = 'invalid_provider_response';
    case ProviderMisconfigured = 'provider_misconfigured';
    case RedisUnavailable = 'redis_unavailable';
    case DuplicateSuppressed = 'duplicate_suppressed';
    case ObsoleteChallenge = 'obsolete_challenge';
    case ExpiredChallenge = 'expired_challenge';
    case ConsumedChallenge = 'consumed_challenge';
    case RevokedChallenge = 'revoked_challenge';
    case FallbackAccepted = 'fallback_accepted';
    case FallbackFailed = 'fallback_failed';
    case Suppressed = 'suppressed';

    public function isTemporaryRetryable(): bool
    {
        return in_array($this, [
            self::Timeout,
            self::TransportError,
            self::RateLimitedByProvider,
            self::ProviderTemporaryFailure,
        ], true);
    }

    public function isFallbackEligible(): bool
    {
        return $this->isTemporaryRetryable();
    }

    public function isPermanent(): bool
    {
        return ! $this->isTemporaryRetryable() && ! in_array($this, [
            self::Accepted,
            self::FallbackAccepted,
            self::DuplicateSuppressed,
            self::Suppressed,
        ], true);
    }
}
