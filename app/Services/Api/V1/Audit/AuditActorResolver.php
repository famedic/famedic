<?php

namespace App\Services\Api\V1\Audit;

use App\Services\Otp\OtpAbuseKeyHasher;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves audit actors without storing credentials.
 *
 * Domain separation: HMAC namespace is `audit|v1|actor|{purpose}` — distinct
 * from idempotency (`idempotency|v1|...`) and OTP abuse buckets.
 */
final class AuditActorResolver
{
    /**
     * Allowlisted system actor keys (without or with `system:` prefix).
     *
     * @var list<string>
     */
    public const SYSTEM_KEYS = [
        'scheduler',
        'console',
        'maintenance',
        'worker',
    ];

    /**
     * Known public purposes (callers may pass others; material must be normalized).
     *
     * @var list<string>
     */
    public const PUBLIC_PURPOSE_EXAMPLES = [
        'login',
        'register',
        'secure_download',
        'secure_link_results_open',
        'secure_link_invoices_open',
    ];

    public function __construct(
        private readonly OtpAbuseKeyHasher $hasher,
    ) {}

    /**
     * Authenticated customer actor. Never reads or stores bearer plaintext.
     *
     * @throws InvalidArgumentException when no customer is bound to the user
     */
    public function resolveAuthenticated(Request $request): AuditActor
    {
        $user = $request->user();
        $customer = $user?->customer;

        if ($user === null || $customer === null) {
            throw new InvalidArgumentException(
                'Authenticated audit actor requires a user with an attached customer.'
            );
        }

        $patId = $this->resolvePersonalAccessTokenId($user->currentAccessToken());

        return new AuditActor(
            type: AuditActor::TYPE_CUSTOMER,
            key: 'customer:'.(string) $customer->id,
            customerId: (int) $customer->id,
            userId: (int) $user->id,
            personalAccessTokenId: $patId,
        );
    }

    /**
     * Public (unauthenticated) actor. Caller MUST declare purpose and pass
     * already-normalized material (never raw OTP, bearer, or passwords).
     *
     * @param  non-empty-string  $purpose  e.g. login, register, secure_download
     * @param  non-empty-string  $normalizedMaterial  identity material already normalized
     *
     * @throws InvalidArgumentException
     */
    public function resolvePublic(string $purpose, string $normalizedMaterial): AuditActor
    {
        $purpose = strtolower(trim($purpose));
        $material = trim($normalizedMaterial);

        if ($purpose === '' || ! preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $purpose)) {
            throw new InvalidArgumentException(
                'Public audit actor requires a non-empty snake/dot purpose (e.g. login, register).'
            );
        }

        if ($material === '') {
            throw new InvalidArgumentException(
                'Public audit actor requires non-empty normalized material.'
            );
        }

        $digest = $this->hasher->hashOpaque(
            'audit|v1|actor|'.$purpose,
            $material
        );

        return new AuditActor(
            type: AuditActor::TYPE_PUBLIC,
            key: 'public:'.$digest,
        );
    }

    /**
     * System actor from an allowlisted key only.
     *
     * @throws InvalidArgumentException
     */
    public function resolveSystem(string $systemKey): AuditActor
    {
        $key = strtolower(trim($systemKey));
        if (str_starts_with($key, 'system:')) {
            $key = substr($key, strlen('system:'));
        }

        if (! in_array($key, self::SYSTEM_KEYS, true)) {
            throw new InvalidArgumentException(
                'System audit actor key is not allowlisted.'
            );
        }

        return new AuditActor(
            type: AuditActor::TYPE_SYSTEM,
            key: 'system:'.$key,
        );
    }

    public function resolvePersonalAccessTokenId(mixed $accessToken): ?int
    {
        if ($accessToken instanceof PersonalAccessToken && $accessToken->id) {
            return (int) $accessToken->id;
        }

        return null;
    }

    /**
     * Hash client IP for optional storage (never plaintext).
     */
    public function hashIp(?string $ip): ?string
    {
        return $this->hasher->hashIp($ip);
    }

    /**
     * Hash user-agent for optional storage (never plaintext).
     */
    public function hashUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $trimmed = trim($userAgent);
        if ($trimmed === '') {
            return null;
        }

        return $this->hasher->hashOpaque('audit|v1|user_agent', mb_substr($trimmed, 0, 512));
    }
}
