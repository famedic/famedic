<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use RuntimeException;

class LaboratoryStructuredResultApprovalException extends RuntimeException
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $reasonCodes = [],
        public readonly array $reasonsHuman = [],
    ) {
        parent::__construct($message);
    }
}
