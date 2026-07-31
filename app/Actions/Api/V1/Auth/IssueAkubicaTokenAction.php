<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\User;
use DateTimeInterface;

/**
 * Issues Sanctum PATs for Akubica API V1 only (token name/abilities from config/akubica.php).
 *
 * P0-C1: when otp.p0a.flags.sanctum_3h_enabled is ON, persists expires_at on the PAT
 * (Akubica-only; does not change config/sanctum.php global expiration used by other modules).
 * When OFF, expires_at stays null and Sanctum Guard uses sanctum.expiration (legacy 1440).
 */
class IssueAkubicaTokenAction
{
    /**
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public function __invoke(User $user): array
    {
        $ttlMinutes = $this->resolveTtlMinutes();
        $expiresAt = now()->addMinutes($ttlMinutes);

        $tokenResult = $user->createToken(
            config('akubica.token_name'),
            config('akubica.token_abilities'),
            $this->shouldPersistExpiresAt() ? $expiresAt : null,
        );

        $accessToken = $tokenResult->accessToken;
        $effectiveExpiresAt = $accessToken->expires_at instanceof DateTimeInterface
            ? $accessToken->expires_at
            : $expiresAt;

        $expiresIn = (int) max(0, $effectiveExpiresAt->getTimestamp() - now()->getTimestamp());

        return [
            'token' => $tokenResult->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'expires_at' => $effectiveExpiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function isSanctum3hEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.sanctum_3h_enabled', false);
    }

    /**
     * Minutes used for Akubica token lifetime (response + optional PAT expires_at).
     */
    public function resolveTtlMinutes(): int
    {
        if ($this->isSanctum3hEnabled()) {
            return (int) config('otp.p0a.sanctum.target_expiration_minutes', 180);
        }

        // Align announced TTL with Sanctum Guard legacy window.
        return (int) config(
            'sanctum.expiration',
            config('akubica.token_ttl_minutes', 1440)
        );
    }

    public function shouldPersistExpiresAt(): bool
    {
        return $this->isSanctum3hEnabled();
    }
}
