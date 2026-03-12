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

            '57' => 'La tarjeta no permite realizar compras en línea. Contacta a tu banco o intenta con otra tarjeta.',

            '91' => 'El banco no está disponible temporalmente. Intenta nuevamente en unos minutos.',

            default => 'No pudimos procesar el pago con tu banco. Intenta con otra tarjeta.'
        };
    }
}