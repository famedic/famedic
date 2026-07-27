<?php

namespace App\Services\Otp\Registration;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Models\OtpChallenge;
use App\Services\Otp\OtpRateLimitDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * P0-A5.4 — Secure registration request/resend + decoy (flag-gated).
 *
 * Does NOT send Notification/Mail/SMS. Does NOT verify OTP or create accounts
 * (P0-A5.5). Decoy store is separate from login P0-A4.
 *
 * Flag matrix:
 * - akubica_register_enabled=false → callers use legacy RegisterController path.
 * - true + infrastructure + anti_abuse → this service.
 * - true without deps → OtpConfigurationException (never fail open).
 */
final class AkubicaRegisterOtpService
{
    public function __construct(
        private readonly AkubicaRegistrationIntentService $intentService,
        private readonly RegistrationCollisionResolver $collisionResolver,
        private readonly AkubicaRegisterOtpDecoyStore $decoyStore,
    ) {
    }

    public static function isEnabled(): bool
    {
        return AkubicaRegistrationPolicy::isEnabled();
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertConfigurationReady(): void
    {
        if (! AkubicaRegistrationPolicy::isEnabled()) {
            throw new OtpConfigurationException(
                'El registro OTP P0-A no esta habilitado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! AkubicaRegistrationPolicy::antiAbuseEnabled()) {
            throw new OtpConfigurationException(
                'akubica_register_enabled requiere anti_abuse_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! AkubicaRegistrationPolicy::infrastructureEnabled()) {
            throw new OtpConfigurationException(
                'akubica_register_enabled requiere infrastructure_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }
    }

    /**
     * Start secure registration: real challenge+intent or uniform decoy.
     *
     * @return array<string, mixed>
     *
     * @throws OtpConfigurationException
     * @throws OtpIdentityNormalizationException ambiguous phone → 422 upstream
     * @throws OtpRateLimitExceededException
     */
    public function request(RegistrationIdentity $identity, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $collision = $this->collisionResolver->resolve($identity->email, $identity->phone);

        if ($collision->kind === RegistrationCollisionKind::AmbiguousPhone
            || $collision->kind === RegistrationCollisionKind::InvalidIdentity
        ) {
            throw new OtpIdentityNormalizationException(
                'Los datos de registro no son validos.',
                'OTP_IDENTITY_INVALID',
            );
        }

        if ($collision->kind->shouldUseDecoy()) {
            return $this->decoyRequestResponse($identity->email->value());
        }

        $result = $this->intentService->createPending($identity, $clientIp);

        return $this->challengeResponsePayload(
            $result->challenge,
            AkubicaRegistrationPolicy::cooldownSeconds(),
        );
    }

    /**
     * Resend for a previously issued challenge_id (real or decoy).
     *
     * @return array<string, mixed>
     */
    public function resend(string $challengePublicId, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $previous = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if ($previous === null) {
            return $this->resendDecoy($challengePublicId);
        }

        if ($previous->purpose !== P0aOtpPurpose::AkubicaRegister->value
            || $previous->context_type !== AkubicaRegistrationIntentService::CONTEXT_TYPE
        ) {
            throw new OtpChallengeMismatchException;
        }

        $intent = $previous->registrationIntent;
        if ($intent === null) {
            throw new OtpChallengeMismatchException;
        }

        $payload = $this->intentService->readPayload((int) $intent->id);
        $result = $this->intentService->createPending(
            $payload->toRegistrationIdentity(),
            $clientIp,
        );

        return $this->challengeResponsePayload(
            $result->challenge,
            AkubicaRegistrationPolicy::cooldownSeconds(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decoyRequestResponse(string $requestedEmail): array
    {
        $this->assertConfigurationReady();

        $ttlMinutes = AkubicaRegistrationPolicy::ttlMinutes();
        $cooldown = AkubicaRegistrationPolicy::cooldownSeconds();
        $now = now();
        $email = strtolower(trim($requestedEmail));
        $publicId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);
        $masked = $this->maskEmail($email);

        $this->decoyStore->put($publicId, [
            'destination_masked' => $masked,
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => AkubicaRegistrationPolicy::maxAttempts(),
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $publicId,
            'purpose' => P0aOtpPurpose::AkubicaRegister->value,
            'channel' => P0aOtpChannel::Email->value,
            'destination_masked' => $masked,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resendDecoy(string $publicId): array
    {
        $previous = $this->decoyStore->get($publicId);
        if ($previous === null) {
            throw new OtpChallengeNotFoundException;
        }

        $cooldown = AkubicaRegistrationPolicy::cooldownSeconds();
        $availableAt = Carbon::createFromTimestamp((int) $previous['last_sent_at'])
            ->utc()
            ->addSeconds($cooldown);

        if ($availableAt->gt(now())) {
            $retryAfter = max(1, $availableAt->getTimestamp() - now()->getTimestamp());

            throw new OtpRateLimitExceededException(OtpRateLimitDecision::deny(
                errorCode: OtpRateLimitDecision::CODE_COOLDOWN,
                publicMessage: 'Espera unos segundos antes de solicitar otro codigo.',
                decision: 'cooldown',
                scope: OtpRateLimitDecision::SCOPE_IDENTITY,
                retryAfterSeconds: $retryAfter,
                availableAt: $availableAt,
                purpose: P0aOtpPurpose::AkubicaRegister->value,
            ));
        }

        $previous['invalidated_at'] = now()->getTimestamp();
        $previous['invalidation_reason'] = 'superseded';
        $this->decoyStore->put($publicId, $previous);

        $ttlMinutes = AkubicaRegistrationPolicy::ttlMinutes();
        $now = now();
        $newId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);

        $this->decoyStore->put($newId, [
            'destination_masked' => $previous['destination_masked'],
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => (int) ($previous['max_attempts'] ?? AkubicaRegistrationPolicy::maxAttempts()),
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $newId,
            'purpose' => P0aOtpPurpose::AkubicaRegister->value,
            'channel' => P0aOtpChannel::Email->value,
            'destination_masked' => $previous['destination_masked'],
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeResponsePayload(OtpChallenge $challenge, int $cooldownSeconds): array
    {
        $sentAt = $challenge->last_sent_at ?? now();
        $resendAvailableAt = $sentAt->copy()->addSeconds($cooldownSeconds);

        return [
            'requires_otp' => true,
            'challenge_id' => $challenge->public_id,
            'purpose' => $challenge->purpose,
            'channel' => $challenge->channel,
            'destination_masked' => $challenge->destination_masked,
            'expires_at' => $challenge->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = $local !== '' ? substr($local, 0, 1) : '*';

        return $prefix.'***@'.$domain;
    }
}
