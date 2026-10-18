<?php

namespace App\Exceptions;

use App\Models\LaboratoryPurchase;
use Illuminate\Support\Str;
use RuntimeException;

class GdaOrderResultUncertainException extends RuntimeException
{
    public readonly string $correlationId;

    public function __construct(private readonly string $failureReason = 'gda_response_uncertain', private ?LaboratoryPurchase $purchase = null)
    {
        $this->correlationId = (string) Str::uuid();
        parent::__construct('Pago recibido; la orden GDA requiere validación. Correlación: '.$this->correlationId);
    }

    public static function forResponse(string $reason, ?int $laboratoryPurchaseId = null, ?int $httpStatus = null, ?array $responseSummary = null, ?\Throwable $previous = null): self
    {
        // Provider text, bodies and previous exceptions are intentionally excluded.
        return new self;
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
            'reason' => in_array($this->failureReason, ['gda_response_uncertain', 'gda_invalid_identifiers', 'gda_replay_unresolved', 'gda_persistence_failed'], true)
                ? $this->failureReason : 'gda_response_uncertain',
            'purchase_id' => $this->purchase?->id, 'correlation_id' => $this->correlationId];
    }
}
