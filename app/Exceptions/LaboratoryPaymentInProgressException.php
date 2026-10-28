<?php

namespace App\Exceptions;

use RuntimeException;

class LaboratoryPaymentInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Estamos verificando el estado de tu pago. No realices otro pago mientras se revisa.');
    }
}
