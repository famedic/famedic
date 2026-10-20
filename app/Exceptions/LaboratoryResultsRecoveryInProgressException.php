<?php

namespace App\Exceptions;

use RuntimeException;

class LaboratoryResultsRecoveryInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Laboratory results recovery is already in progress.');
    }
}
