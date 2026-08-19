<?php

namespace App\Services\Odessa\PreEnrollment;

use RuntimeException;

class OdessaPreEnrollmentImportException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
    ) {
        parent::__construct($codeName);
    }
}
