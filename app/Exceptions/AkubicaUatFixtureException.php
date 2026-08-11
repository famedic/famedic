<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AkubicaUatFixtureException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
