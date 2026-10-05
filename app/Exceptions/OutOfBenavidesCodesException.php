<?php

namespace App\Exceptions;

use RuntimeException;

class OutOfBenavidesCodesException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No Benavides codes are available.');
    }
}
