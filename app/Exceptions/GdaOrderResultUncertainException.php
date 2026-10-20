<?php

namespace App\Exceptions;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Str;
use RuntimeException;

class GdaOrderResultUncertainException extends RuntimeException
{
    public readonly string $correlationId;

    public function __construct(private readonly string $failureReason = 'gda_response_uncertain', private ?LaboratoryPurchase $purchase = null, private ?int $laboratoryPurchaseId = null, private ?int $status = null, private ?array $responseSummary = null)
    {
        $this->correlationId = (string) Str::uuid();
        parent::__construct('Pago recibido; la orden GDA requiere validación. Correlación: '.$this->correlationId);
    }

    public static function forResponse(string $reason, ?int $laboratoryPurchaseId = null, ?int $httpStatus = null, ?array $responseSummary = null, ?\Throwable $previous = null): self
    {
        // Provider diagnostics stay out of operational context and exception messages.
        return new self($reason, laboratoryPurchaseId: $laboratoryPurchaseId, status: $httpStatus, responseSummary: $responseSummary);
    }

    public static function forInvariant(string $reason, LaboratoryPurchase $purchase): self
    {
        return new self($reason, $purchase);
    }

    public function reason(): string
    {
        return in_array($this->failureReason, [
            'gda_response_uncertain', 'gda_invalid_identifiers', 'gda_replay_unresolved', 'gda_persistence_failed',
            'gda_http_failed', 'gda_connection_error', 'gda_unexpected_exception',
            'gda_empty_or_malformed_json', 'gda_missing_required_identifiers', 'gda_completion_invariant_failed',
        ], true) ? $this->failureReason : 'gda_response_uncertain';
    }

    public function httpStatus(): ?int
    {
        return $this->status;
    }

    /** Provider diagnostics are available only to the administrative failure ledger. */
    public function responseSummary(): ?array
    {
        return $this->responseSummary;
    }

    public function withPurchase(LaboratoryPurchase $purchase): self
    {
        $this->purchase = $purchase;
        return $this;
    }

    public function purchase(): ?LaboratoryPurchase
    {
        return $this->purchase;
    }

    public function context(): array
    {
        return ['error_code' => 'gda_order_uncertain', 'stage' => 'gda_order',
            'reason' => $this->reason(),
            'purchase_id' => $this->purchase?->id ?? $this->laboratoryPurchaseId,
            'laboratory_purchase_id' => $this->purchase?->id ?? $this->laboratoryPurchaseId,
            'http_status' => $this->httpStatus(), 'correlation_id' => $this->correlationId];
    }
}
