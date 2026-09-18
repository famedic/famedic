<?php

namespace App\Services\Laboratory;

use RuntimeException;

class SelectedLaboratoryStoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
