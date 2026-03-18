<?php

namespace App\Support;

class PaymentErrorClassifier
{
    public static function message(?string $code): string
    {
        return match ($code) {

            '00' => 'Pago aprobado',

            '05' => 'El banco rechazó la operación. Intenta con otra tarjeta.',

            '51' => 'La tarjeta no tiene fondos suficientes.',

            '57' => 'Tu tarjeta no tiene habilitadas compras en línea o el banco la bloqueó.',

            '91' => 'El banco no está disponible temporalmente. Intenta nuevamente en unos minutos.',

            default => 'No pudimos procesar el pago con tu banco. Intenta con otra tarjeta.'
        };
    }
}