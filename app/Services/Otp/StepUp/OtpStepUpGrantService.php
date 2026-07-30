<?php

namespace App\Services\Otp\StepUp;

use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Models\OtpChallenge;
use App\Models\OtpStepUpGrant;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * P0-B1 — Temporary step-up authorization grants (no downloads / secure links).
 */
class OtpStepUpGrantService
{
    /**
     * @return list<string>
     */
    public static function allowedPurposes(): array
    {
        return [
            P0aOtpPurpose::StepUpResults->value,
            P0aOtpPurpose::StepUpInvoices->value,
        ];
    }

    public function grantTtlMinutes(): int
    {
        return (int) config('otp.p0a.step_up.grant_ttl_minutes', 10);
    }

    public function bindToSanctumToken(): bool
    {
        return (bool) config('otp.p0a.step_up.bind_to_sanctum_token', true);
    }

    public function bindToPurpose(): bool
    {
        return (bool) config('otp.p0a.step_up.bind_to_purpose', true);
    }

    public function bindToResource(): bool
    {
        return (bool) config('otp.p0a.step_up.bind_to_resource', true);
    }

    /**
     * Issue a grant after a consumed step-up challenge. Revokes prior active grants
     * for the same user + purpose + resource (+ token when bound).
     *
     * @throws OtpConfigurationException
     */
    public function issue(
        OtpChallenge $challenge,
        string $purpose,
        string $resourceType,
        int $resourceId,
        int $userId,
        ?int $personalAccessTokenId,
    ): OtpStepUpGrant {
        if (! in_array($purpose, self::allowedPurposes(), true)) {
            throw new OtpConfigurationException(
                'Proposito de step-up no permitido.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if ($this->bindToSanctumToken() && $personalAccessTokenId === null) {
            throw new OtpConfigurationException(
                'Se requiere un token Sanctum persistente para emitir el grant.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        $this->revokeActiveForBinding(
            userId: $userId,
            purpose: $purpose,
            resourceType: $resourceType,
            resourceId: $resourceId,
            personalAccessTokenId: $personalAccessTokenId,
            reason: 'superseded',
        );

        $now = now();
        $ttl = $this->grantTtlMinutes();

        return OtpStepUpGrant::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'personal_access_token_id' => $personalAccessTokenId,
            'otp_challenge_id' => $challenge->id,
            'purpose' => $purpose,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'granted_at' => $now,
            'expires_at' => $now->copy()->addMinutes($ttl),
            'revoked_at' => null,
        ]);
    }

    /**
     * Validate binding: user, purpose, resource, Sanctum token, not expired/revoked.
     */
    public function findValid(
        string $grantPublicId,
        int $userId,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
    ): ?OtpStepUpGrant {
        $grant = OtpStepUpGrant::query()->where('public_id', $grantPublicId)->first();

        if ($grant === null || ! $this->matchesBinding(
            $grant,
            $userId,
            $purpose,
            $resourceType,
            $resourceId,
            $personalAccessTokenId,
        )) {
            return null;
        }

        return $grant->isActive() ? $grant : null;
    }

    /**
     * Find any active grant for the binding (used by future download gates).
     */
    public function findActiveForBinding(
        int $userId,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
    ): ?OtpStepUpGrant {
        $query = OtpStepUpGrant::query()
            ->active()
            ->where('user_id', $userId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId);

        if ($this->bindToPurpose()) {
            $query->where('purpose', $purpose);
        }

        if ($this->bindToSanctumToken()) {
            if ($personalAccessTokenId === null) {
                return null;
            }
            $query->where('personal_access_token_id', $personalAccessTokenId);
        }

        return $query->orderByDesc('granted_at')->first();
    }

    public function matchesBinding(
        OtpStepUpGrant $grant,
        int $userId,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
    ): bool {
        if ((int) $grant->user_id !== $userId) {
            return false;
        }

        if ($this->bindToPurpose() && $grant->purpose !== $purpose) {
            return false;
        }

        if ($this->bindToResource()
            && ($grant->resource_type !== $resourceType || (int) $grant->resource_id !== $resourceId)
        ) {
            return false;
        }

        if ($this->bindToSanctumToken()) {
            if ($personalAccessTokenId === null
                || $grant->personal_access_token_id === null
                || (int) $grant->personal_access_token_id !== $personalAccessTokenId
            ) {
                return false;
            }
        }

        return true;
    }

    public function revoke(OtpStepUpGrant $grant): void
    {
        if ($grant->revoked_at === null) {
            $grant->update(['revoked_at' => now()]);
        }
    }

    public function revokeByPublicId(string $publicId): bool
    {
        $grant = OtpStepUpGrant::query()->where('public_id', $publicId)->first();
        if ($grant === null) {
            return false;
        }

        $this->revoke($grant);

        return true;
    }

    /**
     * Revoke active grants matching the binding (replacement / logout hygiene).
     */
    public function revokeActiveForBinding(
        int $userId,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
        string $reason = 'superseded',
    ): int {
        unset($reason);

        $query = OtpStepUpGrant::query()
            ->active()
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId);

        if ($this->bindToSanctumToken() && $personalAccessTokenId !== null) {
            $query->where('personal_access_token_id', $personalAccessTokenId);
        }

        $count = 0;
        foreach ($query->get() as $grant) {
            $this->revoke($grant);
            $count++;
        }

        return $count;
    }

    /**
     * Soft-invalidate expired grants by stamping revoked_at (idempotent housekeeping).
     */
    public function invalidateExpired(?\DateTimeInterface $now = null): int
    {
        $now ??= now();

        return OtpStepUpGrant::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', $now)
            ->update(['revoked_at' => $now]);
    }

    /**
     * Resolve Sanctum PAT id from the current access token when stable.
     */
    public function resolvePersonalAccessTokenId(mixed $accessToken): ?int
    {
        if ($accessToken instanceof PersonalAccessToken && $accessToken->id) {
            return (int) $accessToken->id;
        }

        return null;
    }

    /**
     * @return array{grant_id: string, purpose: string, resource_type: string, resource_id: int, expires_at: string}
     */
    public function toPublicPayload(OtpStepUpGrant $grant): array
    {
        return [
            'grant_id' => $grant->public_id,
            'purpose' => $grant->purpose,
            'resource_type' => $grant->resource_type,
            'resource_id' => (int) $grant->resource_id,
            'expires_at' => $grant->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
