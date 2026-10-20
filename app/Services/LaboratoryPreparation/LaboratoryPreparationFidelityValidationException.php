<?php

namespace App\Services\LaboratoryPreparation;

use RuntimeException;

class LaboratoryPreparationFidelityValidationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $responsePayload
     * @param  list<string>  $missingElements
     */
    public function __construct(
        string $message,
        public readonly array $responsePayload,
        public readonly ?int $itemId = null,
        public readonly array $missingElements = [],
    ) {
        parent::__construct($message);
    }
}
