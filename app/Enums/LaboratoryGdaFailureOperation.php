<?php

namespace App\Enums;

enum LaboratoryGdaFailureOperation: string
{
    case Checkout = 'checkout';
    case AdminRecover = 'admin_recover';
    case AdminReplace = 'admin_replace';
    case AdminValidation = 'admin_validation';

    public function label(): string
    {
        return match ($this) {
            self::Checkout => 'Checkout',
            self::AdminRecover => 'Recuperación admin',
            self::AdminReplace => 'Reemplazo admin',
            self::AdminValidation => 'Validación admin',
        };
    }
}
