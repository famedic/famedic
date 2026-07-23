<?php

namespace App\Services\Otp;

use App\Enums\P0aOtpPurpose;

/**
 * Deterministic HMAC digests for anti-abuse buckets.
 *
 * Cryptographic decision (P0-A3):
 * - Use HMAC-SHA256 with the application key (config('app.key')).
 * - Fast, keyed, deterministic — suitable for lookup/group keys.
 * - Do NOT use password hashes (bcrypt/argon) for bucket keys.
 * - Never persist plaintext IP, email, or phone in rate-limit tables.
 */
class OtpAbuseKeyHasher
{
    public function hashIdentity(
        P0aOtpPurpose $purpose,
        ?int $userId = null,
        ?string $subjectType = null,
        ?string $subjectKey = null,
        ?string $contextType = null,
        string|int|null $contextId = null,
    ): string {
        $material = implode('|', [
            'identity',
            'v1',
            $purpose->value,
            $userId === null ? '' : (string) $userId,
            $subjectType === null ? '' : strtolower(trim($subjectType)),
            $subjectKey === null ? '' : $this->normalizeSubjectKey($subjectKey),
            $contextType === null ? '' : strtolower(trim($contextType)),
            $contextId === null || $contextId === '' ? '' : (string) $contextId,
        ]);

        return $this->hmac($material);
    }

    /**
     * @return non-empty-string|null null when IP is absent or unparseable
     */
    public function hashIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $normalized = $this->normalizeIp($ip);
        if ($normalized === null) {
            return null;
        }

        return $this->hmac('ip|v1|'.$normalized);
    }

    /**
     * Canonical identity definition for anti-abuse:
     * purpose + user_id (if any) + subject_type/key + context_type/id.
     * Subject keys are lowercased/trimmed; emails stay as normalized subject keys
     * from P0-A2 callers (already destination-normalized upstream).
     */
    public function normalizeSubjectKey(string $subjectKey): string
    {
        return mb_strtolower(trim($subjectKey));
    }

    public function normalizeIp(string $ip): ?string
    {
        $candidate = trim($ip);
        if ($candidate === '') {
            return null;
        }

        // Drop IPv6 zone identifiers (fe80::1%eth0).
        if (str_contains($candidate, '%')) {
            $candidate = explode('%', $candidate, 2)[0];
        }

        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return null;
        }

        $canonical = @inet_ntop($packed);
        if ($canonical === false) {
            return null;
        }

        return $canonical;
    }

    private function hmac(string $material): string
    {
        return hash_hmac('sha256', $material, $this->applicationKey());
    }

    private function applicationKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key !== '' ? $key : 'otp-p0a3-fallback-key';
    }
}
