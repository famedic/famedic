<?php

namespace App\Exceptions;

use App\Models\LaboratoryPurchase;
use RuntimeException;
use Throwable;

class GdaOrderResultUncertainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $responseSummary
     */
    public function __construct(
        string $message = 'El resultado de creación de la orden GDA es incierto.',
        private readonly string $reason = 'unknown',
        private readonly ?int $laboratoryPurchaseId = null,
        private readonly ?int $httpStatus = null,
        private readonly ?array $responseSummary = null,
        ?Throwable $previous = null,
        private ?LaboratoryPurchase $purchase = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forResponse(
        string $reason,
        ?int $laboratoryPurchaseId = null,
        ?int $httpStatus = null,
        ?array $responseSummary = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: 'Pago recibido; la orden GDA requiere validación manual.',
            reason: $reason,
            laboratoryPurchaseId: $laboratoryPurchaseId,
            httpStatus: $httpStatus,
            responseSummary: $responseSummary,
            previous: $previous,
        );
    }

    public static function forInvariant(
        string $reason,
        LaboratoryPurchase $purchase,
    ): self {
        return new self(
            message: 'La compra no tiene confirmación GDA suficiente para completar el carrito.',
            reason: $reason,
            laboratoryPurchaseId: $purchase->id,
            purchase: $purchase,
        );
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

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'reason' => $this->reason,
            'laboratory_purchase_id' => $this->laboratoryPurchaseId ?? $this->purchase?->id,
            'http_status' => $this->httpStatus,
            'response_summary' => $this->responseSummary,
        ];
    }
}
