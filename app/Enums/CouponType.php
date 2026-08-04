<?php

namespace App\Enums;

enum CouponType: string
{
    case Balance = 'balance';
    case Coupon = 'coupon';

    public function label(): string
    {
        return match ($this) {
            self::Balance => 'Saldo a favor',
            self::Coupon => 'Cupón',
        };
    }
}
