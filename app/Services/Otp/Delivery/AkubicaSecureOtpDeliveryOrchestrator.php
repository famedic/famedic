<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Notifications\Api\V1\Auth\AkubicaSecureRegisterOtpMailNotification;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use App\Services\Otp\Registration\RegistrationIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

final class AkubicaSecureOtpDeliveryOrchestrator
{
    public function __construct(
        private readonly OtpDeliveryReservationStore $reservations,
        private readonly OtpDeliveryProvider $provider,
        private readonly OtpAbuseKeyHasher $hasher,
        private readonly OtpDeliveryObservability $observability,
    ) {
    }

    public function deliverRegisterSafely(
        OtpChallenge $challenge,
        string $plainCode,
        RegistrationIdentity $identity,
        string $correlationId,
    ): void {
        if (! AkubicaRegistrationPolicy::deliveryEnabled()) {
            return;
        }

        if (app()->environment('production') && in_array(config('otp.p0a.delivery.driver', 'null'), ['null', 'fake'], true)) {
            throw new \App\Exceptions\Otp\OtpConfigurationException(
                'La entrega SMS OTP no esta configurada.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        $this->reservations->assertAvailable();
        $operationKey = $this->hasher->hashOpaque('delivery|v1|'.$challenge->purpose, (string) $challenge->public_id);
        $ttl = (int) config('otp.p0a.delivery.reservation_ttl_seconds', 600);
        if (! $this->reservations->reserve($operationKey, $ttl)) {
            $this->observability->emit('otp_delivery_duplicate_suppressed', [
                'purpose' => $challenge->purpose, 'channel' => 'sms', 'provider_alias' => $this->provider->alias(),
                'result_class' => OtpDeliveryResultClass::DuplicateSuppressed->value,
            ]);
            return;
        }

        try {
            $operation = OtpDeliveryOperation::create([
                'operation_key' => $operationKey,
                'otp_challenge_id' => $challenge->id,
                'purpose' => $challenge->purpose,
                'status' => 'pending',
                'primary_channel' => 'sms',
                'correlation_id' => $correlationId,
            ]);
        } catch (UniqueConstraintViolationException|\Illuminate\Database\QueryException) {
            $this->reservations->release($operationKey);
            return;
        }

        $current = OtpChallenge::query()->find($challenge->id);
        if (! $current instanceof OtpChallenge || ! $current->isPending()) {
            $class = $this->obsoleteClass($current);
            $operation->update(['status' => 'suppressed', 'result_class' => $class->value]);
            $this->reservations->release($operationKey);
            $this->observe('otp_delivery_suppressed', $current?->purpose ?? $challenge->purpose, 'sms', new OtpDeliveryResult($class, null, 0, 0, $this->provider->alias()));
            return;
        }

        $phone = $identity->phone->e164();
        if ($phone === null || $phone === '') {
            if (AkubicaRegistrationPolicy::emailFallbackEnabled()) {
                $this->deliverEmail($operation, $operationKey, $ttl, $plainCode, $identity, $challenge->purpose);
            } else {
                $operation->update(['status' => 'suppressed', 'result_class' => OtpDeliveryResultClass::Suppressed->value]);
                $this->reservations->release($operationKey);
            }
            return;
        }

        $result = $this->provider->send(new OtpDeliveryRequest(
            $challenge->purpose, 'sms', $phone, $plainCode, $correlationId, 1, null,
        ));
        $this->observe('otp_delivery_attempted', $challenge->purpose, 'sms', $result);
        $operation->update([
            'status' => $result->resultClass === OtpDeliveryResultClass::Accepted ? 'sms_accepted'
                : ($result->resultClass->isTemporaryRetryable() ? 'sms_temporary_failed' : 'sms_permanent_failed'),
            'provider_alias' => $result->providerAlias,
            'result_class' => $result->resultClass->value,
            'attempt_count' => $result->attemptNumber,
        ]);

        if ($result->resultClass === OtpDeliveryResultClass::Accepted) {
            $this->reservations->markAccepted($operationKey, $ttl);
            return;
        }

        if ($result->resultClass->isFallbackEligible() && AkubicaRegistrationPolicy::emailFallbackEnabled()) {
            $this->deliverEmail($operation, $operationKey, $ttl, $plainCode, $identity, $challenge->purpose);
            return;
        }

        $this->reservations->release($operationKey);
    }

    private function deliverEmail(OtpDeliveryOperation $operation, string $operationKey, int $ttl, string $code, RegistrationIdentity $identity, string $purpose): void
    {
        try {
            Notification::route('mail', $identity->email->value())
                ->notify(new AkubicaSecureRegisterOtpMailNotification($code));
            $operation->update([
                'status' => 'email_accepted', 'fallback_used' => true,
                'result_class' => OtpDeliveryResultClass::FallbackAccepted->value,
            ]);
            $result = new OtpDeliveryResult(OtpDeliveryResultClass::FallbackAccepted, null, 1, 0, 'mail');
            $this->reservations->markAccepted($operationKey, $ttl);
        } catch (\Throwable) {
            $operation->update([
                'fallback_used' => true, 'result_class' => OtpDeliveryResultClass::FallbackFailed->value,
            ]);
            $result = new OtpDeliveryResult(OtpDeliveryResultClass::FallbackFailed, null, 1, 0, 'mail');
            $this->reservations->release($operationKey);
        }
        $this->observe('otp_delivery_fallback', $purpose, 'email', $result);
    }

    private function obsoleteClass(?OtpChallenge $challenge): OtpDeliveryResultClass
    {
        return match (true) {
            $challenge === null => OtpDeliveryResultClass::ObsoleteChallenge,
            $challenge->isConsumed() => OtpDeliveryResultClass::ConsumedChallenge,
            $challenge->isExpired() => OtpDeliveryResultClass::ExpiredChallenge,
            $challenge->isInvalidated() => OtpDeliveryResultClass::RevokedChallenge,
            default => OtpDeliveryResultClass::ObsoleteChallenge,
        };
    }

    private function observe(string $event, string $purpose, string $channel, OtpDeliveryResult $result): void
    {
        $this->observability->emit($event, [
            'purpose' => $purpose, 'channel' => $channel, 'provider_alias' => $result->providerAlias,
            'result_class' => $result->resultClass->value, 'attempt_number' => $result->attemptNumber,
            'http_status_class' => $result->httpStatusClass, 'duration_bucket' => $this->observability->durationBucket($result->durationMs),
        ]);
    }
}
