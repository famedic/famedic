<?php

namespace App\Services\Api\V1\Audit;

use App\Models\OtpStepUpGrant;
use App\Support\Api\V1\ApiErrorRetryability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Typed audit emitter for secure-link creation/open and Bearer PDF downloads (Block 3).
 *
 * Controllers call this after confirmed outcomes. Fail-soft via AuditEventWriter.
 * Never stores secure-link tokens, public IDs, URLs, bearer, X-Step-Up-Grant,
 * filesystem paths, or document contents.
 */
final class DocumentAccessAuditRecorder
{
    public const PURPOSE_RESULTS = 'results';

    public const PURPOSE_INVOICES = 'invoices';

    public const ACTOR_PURPOSE_RESULTS_OPEN = 'secure_link_results_open';

    public const ACTOR_PURPOSE_INVOICES_OPEN = 'secure_link_invoices_open';

    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function enabled(): bool
    {
        return $this->writer->enabled();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordResultsSecureLinkCreated(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $secureLinkRowId = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        ?int $ttlMinutes = null,
        ?int $maxOpens = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitCreated(
            eventName: AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED,
            purpose: self::PURPOSE_RESULTS,
            request: $request,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE : null,
            resourceKey: $resourceKey,
            secureLinkRowId: $secureLinkRowId,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: null,
            ttlMinutes: $ttlMinutes,
            maxOpens: $maxOpens,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordInvoicesSecureLinkCreated(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $secureLinkRowId = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        ?int $invoiceRowId = null,
        ?int $ttlMinutes = null,
        ?int $maxOpens = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitCreated(
            eventName: AuditEventDefinitions::EVENT_INVOICES_SECURE_LINK_CREATED,
            purpose: self::PURPOSE_INVOICES,
            request: $request,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_INVOICE : null,
            resourceKey: $resourceKey,
            secureLinkRowId: $secureLinkRowId,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: $invoiceRowId,
            ttlMinutes: $ttlMinutes,
            maxOpens: $maxOpens,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * Public secure-link open for results. Material is the presented opaque token (in-memory only).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordResultsSecureLinkOpened(
        Request $request,
        string $presentedToken,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $secureLinkRowId = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        ?int $openNumber = null,
        ?int $maxOpens = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitOpened(
            eventName: AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED,
            actorPurpose: self::ACTOR_PURPOSE_RESULTS_OPEN,
            purpose: self::PURPOSE_RESULTS,
            request: $request,
            presentedToken: $presentedToken,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE : null,
            resourceKey: $resourceKey,
            secureLinkRowId: $secureLinkRowId,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: null,
            openNumber: $openNumber,
            maxOpens: $maxOpens,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * Public secure-link open for invoices. Material is the presented opaque token (in-memory only).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordInvoicesSecureLinkOpened(
        Request $request,
        string $presentedToken,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $secureLinkRowId = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        ?int $invoiceRowId = null,
        ?int $openNumber = null,
        ?int $maxOpens = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitOpened(
            eventName: AuditEventDefinitions::EVENT_INVOICES_SECURE_LINK_OPENED,
            actorPurpose: self::ACTOR_PURPOSE_INVOICES_OPEN,
            purpose: self::PURPOSE_INVOICES,
            request: $request,
            presentedToken: $presentedToken,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_INVOICE : null,
            resourceKey: $resourceKey,
            secureLinkRowId: $secureLinkRowId,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: $invoiceRowId,
            openNumber: $openNumber,
            maxOpens: $maxOpens,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordResultsDownloaded(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitDownloaded(
            eventName: AuditEventDefinitions::EVENT_RESULTS_DOWNLOADED,
            purpose: self::PURPOSE_RESULTS,
            request: $request,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE : null,
            resourceKey: $resourceKey,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: null,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordInvoicesDownloaded(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?int $stepUpRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $orderRowId = null,
        ?int $invoiceRowId = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emitDownloaded(
            eventName: AuditEventDefinitions::EVENT_INVOICES_DOWNLOADED,
            purpose: self::PURPOSE_INVOICES,
            request: $request,
            outcome: $outcome,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? OtpStepUpGrant::RESOURCE_INVOICE : null,
            resourceKey: $resourceKey,
            stepUpRowId: $stepUpRowId,
            laboratoryPurchaseRowId: $laboratoryPurchaseRowId,
            orderRowId: $orderRowId,
            invoiceRowId: $invoiceRowId,
            metadata: $metadata,
            markTerminal: $markTerminal,
        );
    }

    /**
     * @return array{outcome: string, http_status: int, error_code: string|null, retryable: bool|null}
     */
    public function classifyErrorResponse(JsonResponse $response): array
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);
        $errorCode = is_array($payload) && is_array($payload['error'] ?? null)
            ? (is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null)
            : null;

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $status)
            : null;

        $outcome = match (true) {
            $status >= 500 => AuditOutcome::FAILED,
            $status === 429 => AuditOutcome::REJECTED,
            $status >= 400 => AuditOutcome::REJECTED,
            default => AuditOutcome::FAILED,
        };

        if (in_array($errorCode, [
            'DOCUMENT_STORAGE_UNAVAILABLE',
            'FEATURE_DISABLED',
            'OTP_CONFIGURATION_INVALID',
            'OTP_TEMPORARY_UNAVAILABLE',
        ], true)) {
            $outcome = AuditOutcome::FAILED;
        }

        return [
            'outcome' => $outcome,
            'http_status' => $status,
            'error_code' => $errorCode,
            'retryable' => $retryable,
        ];
    }

    /**
     * Map domain purpose (step_up_results|step_up_invoices) to audit purpose enum.
     */
    public function auditPurposeFromLinkPurpose(string $linkPurpose): ?string
    {
        return match ($linkPurpose) {
            'step_up_results' => self::PURPOSE_RESULTS,
            'step_up_invoices' => self::PURPOSE_INVOICES,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emitCreated(
        string $eventName,
        string $purpose,
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode,
        ?string $resourceType,
        ?string $resourceKey,
        ?int $secureLinkRowId,
        ?int $stepUpRowId,
        ?int $laboratoryPurchaseRowId,
        ?int $orderRowId,
        ?int $invoiceRowId,
        ?int $ttlMinutes,
        ?int $maxOpens,
        array $metadata,
        bool $markTerminal,
    ): void {
        $this->emit(
            eventName: $eventName,
            outcome: $outcome,
            actor: $this->safeAuthenticatedActor($request),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'purpose' => $purpose,
                'secure_link_row_id' => $secureLinkRowId,
                'step_up_row_id' => $stepUpRowId,
                'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
                'order_row_id' => $orderRowId,
                'invoice_row_id' => $invoiceRowId,
                'ttl_minutes' => $ttlMinutes,
                'max_opens' => $maxOpens,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emitOpened(
        string $eventName,
        string $actorPurpose,
        string $purpose,
        Request $request,
        string $presentedToken,
        string $outcome,
        int $httpStatus,
        ?string $errorCode,
        ?string $resourceType,
        ?string $resourceKey,
        ?int $secureLinkRowId,
        ?int $stepUpRowId,
        ?int $laboratoryPurchaseRowId,
        ?int $orderRowId,
        ?int $invoiceRowId,
        ?int $openNumber,
        ?int $maxOpens,
        array $metadata,
        bool $markTerminal,
    ): void {
        $this->emit(
            eventName: $eventName,
            outcome: $outcome,
            actor: $this->safePublicActor($actorPurpose, $presentedToken),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'purpose' => $purpose,
                'secure_link_row_id' => $secureLinkRowId,
                'step_up_row_id' => $stepUpRowId,
                'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
                'order_row_id' => $orderRowId,
                'invoice_row_id' => $invoiceRowId,
                'open_number' => $openNumber,
                'max_opens' => $maxOpens,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emitDownloaded(
        string $eventName,
        string $purpose,
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode,
        ?string $resourceType,
        ?string $resourceKey,
        ?int $stepUpRowId,
        ?int $laboratoryPurchaseRowId,
        ?int $orderRowId,
        ?int $invoiceRowId,
        array $metadata,
        bool $markTerminal,
    ): void {
        $this->emit(
            eventName: $eventName,
            outcome: $outcome,
            actor: $this->safeAuthenticatedActor($request),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'purpose' => $purpose,
                'step_up_row_id' => $stepUpRowId,
                'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
                'order_row_id' => $orderRowId,
                'invoice_row_id' => $invoiceRowId,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emit(
        string $eventName,
        string $outcome,
        ?AuditActor $actor,
        Request $request,
        int $httpStatus,
        ?string $errorCode,
        array $metadata,
        bool $markTerminal,
        ?string $resourceType = null,
        ?string $resourceKey = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        if ($actor === null) {
            return;
        }

        $context = ApiV1AuditContext::fromRequest($request);
        if ($context->actor() === null) {
            $context->setActor($actor);
        }

        $cleanMeta = [];
        foreach ($metadata as $k => $v) {
            if ($v === null) {
                continue;
            }
            $cleanMeta[$k] = $v;
        }

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $httpStatus)
            : ($httpStatus < 400 ? false : null);

        $this->writer->write([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'actor' => $actor,
            'context' => $context,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'retryable' => $retryable,
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'metadata' => $cleanMeta,
            'ip_hash' => $this->actors->hashIp($request->ip()),
            'user_agent_hash' => $this->actors->hashUserAgent($request->userAgent()),
            'mark_terminal' => $markTerminal,
        ]);
    }

    private function safePublicActor(string $purpose, string $normalizedMaterial): ?AuditActor
    {
        try {
            return $this->actors->resolvePublic($purpose, $normalizedMaterial);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }

    private function safeAuthenticatedActor(Request $request): ?AuditActor
    {
        try {
            return $this->actors->resolveAuthenticated($request);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }
}
