<?php

namespace App\Exceptions;

use RuntimeException;

/** The order request was never submitted; existing definitive-failure policy applies. */
class GdaOrderDefinitiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No fue posible preparar la solicitud GDA antes de enviarla.');
    }
}
