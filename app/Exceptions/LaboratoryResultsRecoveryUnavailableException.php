<?php

namespace App\Exceptions;

use RuntimeException;

class LaboratoryResultsRecoveryUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Laboratory results recovery is unavailable.');
    }
}
