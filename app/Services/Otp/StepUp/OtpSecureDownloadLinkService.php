<?php

namespace App\Services\Otp\StepUp;

use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Support\Api\V1\LaboratoryOrderStatus;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P0-B2 — Opaque secure download links for laboratory results PDFs.
 *
 * Grant policy:
 * - Issue requires an active (non-expired, non-revoked) step_up_results grant.
 * - Download requires the related grant is not explicitly revoked.
 * - Grant TTL expiry after issue does NOT invalidate an already-issued link
 *   (link has its own expires_at).
 * - Explicit grant revoke stamps revoked_at on issued links.
 * - Logout / PAT delete does NOT auto-revoke issued links (short TTL risk documented).
 */
class OtpSecureDownloadLinkService
{
    public function __construct(
        private readonly OtpStepUpGrantService $grantService,
        private readonly OrderDocumentDownloadSupport $downloadSupport,
    ) {
    }

    public static function isResultsEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.secure_links_results_enabled', false);
    }

    public function ttlMinutes(): int
    {
        return (int) config('otp.p0a.secure_links.ttl_minutes', 60);
    }

    public function maxOpens(): int
    {
        return (int) config('otp.p0a.secure_links.max_opens', 5);
    }

    public function findOwnedOrder(Customer $customer, int $orderId): ?LaboratoryPurchase
    {
        return $this->downloadSupport->findCustomerOrder($customer, $orderId);
    }

    /**
     * @return array{url: string, expires_at: string, max_opens: int}
     *
     * @throws SecureDownloadLinkException
     */
    public function issueResultsLink(
        User $user,
        LaboratoryPurchase $order,
        string $grantPublicId,
        ?int $personalAccessTokenId,
    ): array {
        if (! self::isResultsEnabled()) {
            throw new SecureDownloadLinkException(
                'Las ligas seguras de resultados no estan habilitadas.',
                'FEATURE_DISABLED',
                503,
            );
        }

        $purpose = P0aOtpPurpose::StepUpResults->value;
        $resourceType = OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE;
        $resourceId = (int) $order->id;

        $grant = $this->grantService->findValid(
            $grantPublicId,
            (int) $user->id,
            $purpose,
            $resourceType,
            $resourceId,
            $personalAccessTokenId,
        );

        if ($grant === null) {
            throw new SecureDownloadLinkException(
                'El grant de step-up no es valido.',
                'STEP_UP_GRANT_INVALID',
                422,
            );
        }

        if (! LaboratoryOrderStatus::hasResults($order)) {
            throw new SecureDownloadLinkException(
                'El resultado aun no esta disponible.',
                'RESULT_NOT_READY',
                409,
            );
        }

        $resolved = $this->downloadSupport->resolveResultPdf($order);
        if (isset($resolved['error'])) {
            throw new SecureDownloadLinkException(
                'El resultado aun no esta disponible.',
                'RESULT_NOT_READY',
                409,
            );
        }

        $plainToken = $this->generateOpaqueToken();
        $tokenHash = $this->hashToken($plainToken);
        $now = now();
        $ttl = max(1, $this->ttlMinutes());
        $maxOpens = max(1, $this->maxOpens());

        $link = OtpSecureDownloadLink::query()->create([
            'public_id' => (string) Str::uuid(),
            'token_hash' => $tokenHash,
            'user_id' => $user->id,
            'personal_access_token_id' => $personalAccessTokenId,
            'otp_step_up_grant_id' => $grant->id,
            'purpose' => $purpose,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'expires_at' => $now->copy()->addMinutes($ttl),
            'max_opens' => $maxOpens,
            'open_count' => 0,
            'consumed_at' => null,
            'revoked_at' => null,
            'last_opened_at' => null,
        ]);

        Log::info('otp_secure_link_issued', [
            'public_id' => $link->public_id,
            'purpose' => $purpose,
            'resource_type' => $resourceType,
            'max_opens' => $maxOpens,
        ]);

        return [
            'url' => route('api.v1.secure-downloads.show', ['token' => $plainToken], absolute: true),
            'expires_at' => $link->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'max_opens' => $maxOpens,
        ];
    }

    /**
     * Resolve PDF and atomically consume one open. PDF is resolved before consume
     * so RESULT_NOT_READY / storage failures do not burn the link.
     *
     * @return array{content: string, filename: string}
     *
     * @throws SecureDownloadLinkException
     */
    public function consumeAndResolvePdf(string $plainToken): array
    {
        if (! self::isResultsEnabled()) {
            throw new SecureDownloadLinkException(
                'Las ligas seguras de resultados no estan habilitadas.',
                'FEATURE_DISABLED',
                503,
            );
        }

        $tokenHash = $this->hashToken($plainToken);
        $link = OtpSecureDownloadLink::query()->where('token_hash', $tokenHash)->first();

        if ($link === null) {
            throw new SecureDownloadLinkException(
                'Liga segura no encontrada.',
                'SECURE_LINK_NOT_FOUND',
                404,
            );
        }

        $this->assertLinkUsable($link);

        $order = LaboratoryPurchase::query()
            ->withTrashed()
            ->find((int) $link->resource_id);

        if ($order === null
            || $link->resource_type !== OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE
        ) {
            throw new SecureDownloadLinkException(
                'El resultado aun no esta disponible.',
                'RESULT_NOT_READY',
                409,
            );
        }

        try {
            $resolved = $this->downloadSupport->resolveResultPdf($order);
        } catch (\Throwable $e) {
            Log::warning('otp_secure_link_storage_failed', [
                'public_id' => $link->public_id,
                'error_class' => $e::class,
            ]);

            throw new SecureDownloadLinkException(
                'El documento no esta disponible temporalmente.',
                'DOCUMENT_STORAGE_UNAVAILABLE',
                503,
            );
        }

        if (isset($resolved['error'])) {
            throw new SecureDownloadLinkException(
                'El resultado aun no esta disponible.',
                'RESULT_NOT_READY',
                409,
            );
        }

        // Atomic consume after bytes are in memory (response() streams from memory).
        // If the HTTP client disconnects mid-transfer, the open is already counted —
        // acceptable for short TTL / max_opens staging target.
        $this->consumeOpenAtomically((int) $link->id);

        return [
            'content' => $resolved['content'],
            'filename' => $resolved['filename'],
        ];
    }

    public function revokeForGrant(OtpStepUpGrant $grant): int
    {
        return OtpSecureDownloadLink::query()
            ->where('otp_step_up_grant_id', $grant->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * @throws SecureDownloadLinkException
     */
    private function assertLinkUsable(OtpSecureDownloadLink $link): void
    {
        if ($link->isRevoked()) {
            throw new SecureDownloadLinkException(
                'La liga segura fue revocada.',
                'SECURE_LINK_REVOKED',
                410,
            );
        }

        $grant = $link->grant;
        if ($grant === null || $grant->isRevoked()) {
            throw new SecureDownloadLinkException(
                'La liga segura fue revocada.',
                'SECURE_LINK_REVOKED',
                410,
            );
        }

        if ($link->isExpired()) {
            throw new SecureDownloadLinkException(
                'La liga segura expiro.',
                'SECURE_LINK_EXPIRED',
                410,
            );
        }

        if ($link->isExhausted()) {
            throw new SecureDownloadLinkException(
                'La liga segura ya fue utilizada.',
                'SECURE_LINK_CONSUMED',
                410,
            );
        }
    }

    /**
     * @throws SecureDownloadLinkException
     */
    private function consumeOpenAtomically(int $linkId): void
    {
        DB::transaction(function () use ($linkId): void {
            /** @var OtpSecureDownloadLink|null $locked */
            $locked = OtpSecureDownloadLink::query()
                ->whereKey($linkId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new SecureDownloadLinkException(
                    'Liga segura no encontrada.',
                    'SECURE_LINK_NOT_FOUND',
                    404,
                );
            }

            $this->assertLinkUsable($locked);

            $openCount = (int) $locked->open_count + 1;
            $attributes = [
                'open_count' => $openCount,
                'last_opened_at' => now(),
            ];

            if ($openCount >= (int) $locked->max_opens) {
                $attributes['consumed_at'] = now();
            }

            $locked->update($attributes);
        });
    }

    private function generateOpaqueToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
