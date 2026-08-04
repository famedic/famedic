<?php

namespace App\Services\Api\V1\Audit;

use App\Enums\LaboratoryBrand;
use App\Support\Api\V1\ApiErrorRetryability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Typed audit emitter for API V1 cart, coupon/balance and checkout draft (Block 4).
 *
 * Controllers call this after confirmed outcomes. Fail-soft via AuditEventWriter.
 * Does not audit payment-link generation, payments, or LaboratoryPurchase creation
 * (those are outside API V1 cart/checkout mutations).
 */
final class CartCheckoutAuditRecorder
{
    public const RESOURCE_LABORATORY_CART = 'laboratory_cart';

    public const RESOURCE_LABORATORY_CART_ITEM = 'laboratory_cart_item';

    public const RESOURCE_LABORATORY_CHECKOUT_DRAFT = 'laboratory_checkout_draft';

    public const BENEFIT_TYPE_BALANCE = 'balance';

    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function enabled(): bool
    {
        return $this->writer->enabled();
    }

    public function cartResourceKey(int $customerId, LaboratoryBrand $brand): string
    {
        return $customerId.':'.$brand->value;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartItemAdded(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $cartItemRowId = null,
        ?int $laboratoryTestRowId = null,
        ?int $itemCount = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CART_ITEM_ADDED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CART_ITEM : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'cart_item_row_id' => $cartItemRowId,
                'laboratory_test_row_id' => $laboratoryTestRowId,
                'item_count' => $itemCount,
                'quantity' => $outcome === AuditOutcome::SUCCEEDED ? 1 : null,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartItemRemoved(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $cartItemRowId = null,
        ?int $laboratoryTestRowId = null,
        ?int $itemCount = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CART_ITEM_REMOVED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CART_ITEM : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'cart_item_row_id' => $cartItemRowId,
                'laboratory_test_row_id' => $laboratoryTestRowId,
                'item_count' => $itemCount,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartCleared(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $itemCount = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CART_CLEARED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CART : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'item_count' => $itemCount,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartBenefitApplied(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $couponRowId = null,
        ?int $appliedAmountMinor = null,
        ?int $itemCount = null,
        ?int $subtotalMinor = null,
        ?int $discountMinor = null,
        ?int $creditMinor = null,
        ?int $totalMinor = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CART : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'benefit_type' => self::BENEFIT_TYPE_BALANCE,
                'coupon_row_id' => $couponRowId,
                'applied_amount_minor' => $appliedAmountMinor,
                'item_count' => $itemCount,
                'subtotal_minor' => $subtotalMinor,
                'discount_minor' => $discountMinor,
                'credit_minor' => $creditMinor,
                'total_minor' => $totalMinor,
                'currency' => 'MXN',
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartBenefitRemoved(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $couponRowId = null,
        ?int $removedAmountMinor = null,
        ?int $itemCount = null,
        ?int $subtotalMinor = null,
        ?int $discountMinor = null,
        ?int $totalMinor = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CART_BENEFIT_REMOVED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CART : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'benefit_type' => self::BENEFIT_TYPE_BALANCE,
                'coupon_row_id' => $couponRowId,
                'removed_amount_minor' => $removedAmountMinor,
                'item_count' => $itemCount,
                'subtotal_minor' => $subtotalMinor,
                'discount_minor' => $discountMinor,
                'credit_minor' => 0,
                'total_minor' => $totalMinor,
                'currency' => 'MXN',
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCheckoutDraftSynced(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $draftRowId = null,
        ?string $checkoutStep = null,
        ?int $itemCount = null,
        ?bool $checkoutReady = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_CHECKOUT_DRAFT_SYNCED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_CHECKOUT_DRAFT : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'draft_row_id' => $draftRowId,
                'checkout_step' => $checkoutStep,
                'item_count' => $itemCount,
                'checkout_ready' => $checkoutReady,
            ], $metadata),
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

        if (in_array($errorCode, ['INTERNAL_ERROR', 'FEATURE_DISABLED'], true)) {
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
     * @param  array<string, mixed>  $metadata
     */
    private function emit(
        string $eventName,
        string $outcome,
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

        $actor = $this->safeAuthenticatedActor($request);
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

    private function safeAuthenticatedActor(Request $request): ?AuditActor
    {
        try {
            return $this->actors->resolveAuthenticated($request);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }
}
