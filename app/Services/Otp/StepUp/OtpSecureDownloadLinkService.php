<?php

namespace App\Services\Otp\StepUp;

use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Models\Customer;
use App\Models\Invoice;
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
 * P0-B2/B3 — Opaque secure download links for results and invoice PDFs.
 *
 * Grant policy (shared):
 * - Issue requires an active grant for the matching purpose/resource.
 * - Download requires the related grant is not explicitly revoked.
 * - Grant TTL expiry after issue does NOT invalidate an already-issued link.
 * - Explicit grant revoke stamps revoked_at on issued links.
 * - Logout / PAT delete does NOT auto-revoke issued links (short TTL risk).
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

    public static function isInvoicesEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.secure_links_invoices_enabled', false);
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

    public function findOwnedInvoice(LaboratoryPurchase $order, int $invoiceId): ?Invoice
    {
        return $this->downloadSupport->findOwnedInvoice($order, $invoiceId);
    }

    /**
     * @return array{
     *     url: string,
     *     expires_at: string,
     *     max_opens: int,
     *     audit: array{
     *         secure_link_row_id: int,
     *         step_up_row_id: int,
     *         ttl_minutes: int,
     *         max_opens: int,
     *         purpose: string,
     *         resource_type: string,
     *         resource_id: int
     *     }
     * }
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

        $grant = $this->requireValidGrant(
            $grantPublicId,
            (int) $user->id,
            $purpose,
            $resourceType,
            $resourceId,
            $personalAccessTokenId,
        );

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

        return $this->persistLink($user, $grant, $purpose, $resourceType, $resourceId, $personalAccessTokenId);
    }

    /**
     * @return array{
     *     url: string,
     *     expires_at: string,
     *     max_opens: int,
     *     audit: array{
     *         secure_link_row_id: int,
     *         step_up_row_id: int,
     *         ttl_minutes: int,
     *         max_opens: int,
     *         purpose: string,
     *         resource_type: string,
     *         resource_id: int
     *     }
     * }
     *
     * @throws SecureDownloadLinkException
     */
    public function issueInvoicesLink(
        User $user,
        LaboratoryPurchase $order,
        Invoice $invoice,
        string $grantPublicId,
        ?int $personalAccessTokenId,
    ): array {
        if (! self::isInvoicesEnabled()) {
            throw new SecureDownloadLinkException(
                'Las ligas seguras de facturas no estan habilitadas.',
                'FEATURE_DISABLED',
                503,
            );
        }

        $purpose = P0aOtpPurpose::StepUpInvoices->value;
        $resourceType = OtpStepUpGrant::RESOURCE_INVOICE;
        $resourceId = (int) $invoice->id;

        $grant = $this->requireValidGrant(
            $grantPublicId,
            (int) $user->id,
            $purpose,
            $resourceType,
            $resourceId,
            $personalAccessTokenId,
        );

        $resolved = $this->downloadSupport->resolveInvoicePdf($order, $resourceId);
        if (isset($resolved['error'])) {
            $code = $resolved['error'] === 'INVOICE_NOT_FOUND'
                ? 'INVOICE_NOT_FOUND'
                : 'INVOICE_NOT_READY';
            $status = $code === 'INVOICE_NOT_FOUND' ? 404 : 409;

            throw new SecureDownloadLinkException(
                $code === 'INVOICE_NOT_FOUND'
                    ? 'Factura no encontrada.'
                    : 'La factura aun no esta disponible.',
                $code,
                $status,
            );
        }

        return $this->persistLink($user, $grant, $purpose, $resourceType, $resourceId, $personalAccessTokenId);
    }

    /**
     * Consume one open and resolve PDF content.
     *
     * Order: validate link → resolve PDF → atomically increment open_count → return.
     * Rejects before consume do not increment opens. Success means open_count was
     * confirmed; the HTTP binary response is built by the controller afterward.
     *
     * @return array{
     *     content: string,
     *     filename: string,
     *     audit: array{
     *         purpose: string,
     *         secure_link_row_id: int,
     *         step_up_row_id: int,
     *         resource_type: string,
     *         resource_id: int,
     *         laboratory_purchase_row_id: int|null,
     *         order_row_id: int|null,
     *         invoice_row_id: int|null,
     *         open_number: int,
     *         max_opens: int
     *     }
     * }
     *
     * @throws SecureDownloadLinkException
     */
    public function consumeAndResolvePdf(string $plainToken): array
    {
        if (! self::isResultsEnabled() && ! self::isInvoicesEnabled()) {
            throw new SecureDownloadLinkException(
                'Las ligas seguras no estan habilitadas.',
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

        $auditBase = $this->auditContextFromLink($link);

        try {
            $this->assertPurposeEnabled($link);
            $this->assertLinkUsable($link);
        } catch (SecureDownloadLinkException $e) {
            throw new SecureDownloadLinkException(
                $e->getMessage(),
                $e->errorCode,
                $e->httpStatus,
                $auditBase,
            );
        }

        try {
            $resolved = $this->resolvePdfForLink($link);
        } catch (SecureDownloadLinkException $e) {
            throw new SecureDownloadLinkException(
                $e->getMessage(),
                $e->errorCode,
                $e->httpStatus,
                $auditBase,
            );
        } catch (\Throwable $e) {
            Log::warning('otp_secure_link_storage_failed', [
                'public_id' => $link->public_id,
                'error_class' => $e::class,
            ]);

            throw new SecureDownloadLinkException(
                'El documento no esta disponible temporalmente.',
                'DOCUMENT_STORAGE_UNAVAILABLE',
                503,
                $auditBase,
            );
        }

        if (isset($resolved['error'])) {
            throw new SecureDownloadLinkException(
                $this->notReadyMessage($link),
                $this->notReadyCode($link),
                409,
                $auditBase,
            );
        }

        $openNumber = $this->consumeOpenAtomically((int) $link->id);

        return [
            'content' => $resolved['content'],
            'filename' => $resolved['filename'],
            'audit' => array_merge($auditBase, [
                'open_number' => $openNumber,
            ]),
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
    private function requireValidGrant(
        string $grantPublicId,
        int $userId,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
    ): OtpStepUpGrant {
        $grant = $this->grantService->findValid(
            $grantPublicId,
            $userId,
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

        return $grant;
    }

    /**
     * @return array{
     *     url: string,
     *     expires_at: string,
     *     max_opens: int,
     *     audit: array{
     *         secure_link_row_id: int,
     *         step_up_row_id: int,
     *         ttl_minutes: int,
     *         max_opens: int,
     *         purpose: string,
     *         resource_type: string,
     *         resource_id: int
     *     }
     * }
     */
    private function persistLink(
        User $user,
        OtpStepUpGrant $grant,
        string $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
    ): array {
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
            'audit' => [
                'secure_link_row_id' => (int) $link->id,
                'step_up_row_id' => (int) $grant->id,
                'ttl_minutes' => $ttl,
                'max_opens' => $maxOpens,
                'purpose' => $purpose,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ],
        ];
    }

    /**
     * @return array{content: string, filename: string}|array{error: string}
     *
     * @throws SecureDownloadLinkException
     */
    private function resolvePdfForLink(OtpSecureDownloadLink $link): array
    {
        if ($link->purpose === P0aOtpPurpose::StepUpResults->value
            && $link->resource_type === OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE
        ) {
            $order = LaboratoryPurchase::query()
                ->withTrashed()
                ->find((int) $link->resource_id);

            if ($order === null) {
                return ['error' => 'RESULT_NOT_READY'];
            }

            return $this->downloadSupport->resolveResultPdf($order);
        }

        if ($link->purpose === P0aOtpPurpose::StepUpInvoices->value
            && $link->resource_type === OtpStepUpGrant::RESOURCE_INVOICE
        ) {
            $invoice = Invoice::query()
                ->withTrashed()
                ->find((int) $link->resource_id);

            if ($invoice === null
                || $invoice->invoiceable_type !== LaboratoryPurchase::class
            ) {
                return ['error' => 'INVOICE_NOT_READY'];
            }

            $order = LaboratoryPurchase::query()
                ->withTrashed()
                ->find((int) $invoice->invoiceable_id);

            if ($order === null) {
                return ['error' => 'INVOICE_NOT_READY'];
            }

            return $this->downloadSupport->resolveInvoicePdf($order, (int) $invoice->id);
        }

        // Unknown purpose/resource pairing — do not leak document type.
        throw new SecureDownloadLinkException(
            'Liga segura no encontrada.',
            'SECURE_LINK_NOT_FOUND',
            404,
        );
    }

    /**
     * @throws SecureDownloadLinkException
     */
    private function assertPurposeEnabled(OtpSecureDownloadLink $link): void
    {
        $enabled = match ($link->purpose) {
            P0aOtpPurpose::StepUpResults->value => self::isResultsEnabled(),
            P0aOtpPurpose::StepUpInvoices->value => self::isInvoicesEnabled(),
            default => false,
        };

        if (! $enabled) {
            throw new SecureDownloadLinkException(
                'Las ligas seguras no estan habilitadas.',
                'FEATURE_DISABLED',
                503,
            );
        }
    }

    private function notReadyCode(OtpSecureDownloadLink $link): string
    {
        return $link->purpose === P0aOtpPurpose::StepUpInvoices->value
            ? 'INVOICE_NOT_READY'
            : 'RESULT_NOT_READY';
    }

    private function notReadyMessage(OtpSecureDownloadLink $link): string
    {
        return $link->purpose === P0aOtpPurpose::StepUpInvoices->value
            ? 'La factura aun no esta disponible.'
            : 'El resultado aun no esta disponible.';
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
     * Atomically consume one open. Returns the confirmed open_number (1-based).
     *
     * @throws SecureDownloadLinkException
     */
    private function consumeOpenAtomically(int $linkId): int
    {
        return DB::transaction(function () use ($linkId): int {
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

            $audit = $this->auditContextFromLink($locked);

            try {
                $this->assertLinkUsable($locked);
            } catch (SecureDownloadLinkException $e) {
                throw new SecureDownloadLinkException(
                    $e->getMessage(),
                    $e->errorCode,
                    $e->httpStatus,
                    $audit,
                );
            }

            $openCount = (int) $locked->open_count + 1;
            $attributes = [
                'open_count' => $openCount,
                'last_opened_at' => now(),
            ];

            if ($openCount >= (int) $locked->max_opens) {
                $attributes['consumed_at'] = now();
            }

            $locked->update($attributes);

            return $openCount;
        });
    }

    /**
     * Safe internal identifiers for audit — never tokens, public ids, or URLs.
     *
     * @return array{
     *     purpose: string,
     *     secure_link_row_id: int,
     *     step_up_row_id: int,
     *     resource_type: string,
     *     resource_id: int,
     *     laboratory_purchase_row_id: int|null,
     *     order_row_id: int|null,
     *     invoice_row_id: int|null,
     *     max_opens: int,
     *     open_count: int
     * }
     */
    private function auditContextFromLink(OtpSecureDownloadLink $link): array
    {
        $resourceType = (string) $link->resource_type;
        $resourceId = (int) $link->resource_id;
        $laboratoryPurchaseRowId = null;
        $orderRowId = null;
        $invoiceRowId = null;

        if ($link->purpose === P0aOtpPurpose::StepUpResults->value
            && $resourceType === OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE
        ) {
            $laboratoryPurchaseRowId = $resourceId;
            $orderRowId = $resourceId;
        } elseif ($link->purpose === P0aOtpPurpose::StepUpInvoices->value
            && $resourceType === OtpStepUpGrant::RESOURCE_INVOICE
        ) {
            $invoiceRowId = $resourceId;
            $invoice = Invoice::query()->withTrashed()->find($resourceId);
            if ($invoice !== null
                && $invoice->invoiceable_type === LaboratoryPurchase::class
            ) {
                $laboratoryPurchaseRowId = (int) $invoice->invoiceable_id;
                $orderRowId = (int) $invoice->invoiceable_id;
            }
        }

        return [
            'purpose' => (string) $link->purpose,
            'secure_link_row_id' => (int) $link->id,
            'step_up_row_id' => (int) $link->otp_step_up_grant_id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
            'order_row_id' => $orderRowId,
            'invoice_row_id' => $invoiceRowId,
            'max_opens' => (int) $link->max_opens,
            'open_count' => (int) $link->open_count,
        ];
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
