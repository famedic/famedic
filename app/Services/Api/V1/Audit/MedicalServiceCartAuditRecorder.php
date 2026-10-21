<?php

namespace App\Services\Api\V1\Audit;

use App\Models\MedicalServiceCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MedicalServiceCartAuditRecorder
{
    public const RESOURCE = 'medical_service_cart';

    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function recordApplied(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?int $customerId = null,
        ?MedicalServiceCart $cart = null,
    ): void {
        $metadata = [
            'service_line' => 'medical_services',
            'benefit_type' => $cart?->benefits()->with('coupon')->first()?->benefit_type,
            'coupon_row_id' => $cart?->benefits()->first()?->coupon_id,
            'applied_amount_minor' => $cart?->benefits()->first()?->applied_cents,
            'item_count' => $cart?->items()->count(),
            'subtotal_minor' => $cart?->subtotal_cents,
            'discount_minor' => $cart?->discount_cents,
            'credit_minor' => $cart?->credit_applied_cents,
            'total_minor' => $cart?->total_cents,
            'currency' => 'MXN',
        ];

        $this->emit($request, AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED, $outcome, $httpStatus, $errorCode, $customerId, $cart, $metadata);
    }

    public function recordRemoved(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?int $customerId = null,
        ?MedicalServiceCart $cart = null,
        ?int $removedAmountCents = null,
        ?string $removedBenefitType = null,
        ?int $removedCouponId = null,
    ): void {
        $this->emit($request, AuditEventDefinitions::EVENT_CART_BENEFIT_REMOVED, $outcome, $httpStatus, $errorCode, $customerId, $cart, [
            'service_line' => 'medical_services',
            'benefit_type' => $removedBenefitType,
            'coupon_row_id' => $removedCouponId,
            'removed_amount_minor' => $removedAmountCents,
            'item_count' => $cart?->items()->count(),
            'subtotal_minor' => $cart?->subtotal_cents,
            'discount_minor' => $cart?->discount_cents,
            'credit_minor' => $cart?->credit_applied_cents,
            'total_minor' => $cart?->total_cents,
            'currency' => 'MXN',
        ]);
    }

    /** @return array{outcome: string, http_status: int, error_code: string|null} */
    public function classifyErrorResponse(JsonResponse $response): array
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);
        $errorCode = is_array($payload['error'] ?? null) && is_string($payload['error']['code'] ?? null)
            ? $payload['error']['code']
            : null;

        return [
            'outcome' => $status >= 500 ? AuditOutcome::FAILED : AuditOutcome::REJECTED,
            'http_status' => $status,
            'error_code' => $errorCode,
        ];
    }

    private function emit(
        Request $request,
        string $eventName,
        string $outcome,
        int $httpStatus,
        ?string $errorCode,
        ?int $customerId,
        ?MedicalServiceCart $cart,
        array $metadata,
    ): void {
        if (! $this->writer->enabled()) {
            return;
        }

        $actor = $this->actors->resolveAuthenticated($request);
        if ($actor === null) {
            return;
        }

        $this->writer->write([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'actor' => $actor,
            'context' => ApiV1AuditContext::fromRequest($request),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'resource_type' => self::RESOURCE,
            'resource_key' => $cart?->id !== null ? (string) $cart->id : ($customerId !== null ? (string) $customerId : null),
            'metadata' => $metadata,
        ]);
    }
}
