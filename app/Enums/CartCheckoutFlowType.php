<?php

namespace App\Enums;

enum CartCheckoutFlowType: string
{
    case AppointmentFirst = 'appointment_first';
    case Standard = 'standard';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::AppointmentFirst => 'Cita antes del pago',
            self::Standard => 'Flujo estándar',
            self::Unknown => 'Flujo no determinado',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::AppointmentFirst => 'Cita primero',
            self::Standard => 'Estándar',
            self::Unknown => 'Indeterminado',
        };
    }
}
