<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;
use App\Traits\HasCasesWithLabels;

enum VendorPaymentPurchaseType: string implements LabelledEnum
{
    use HasCasesWithLabels;

    case LABORATORY = 'laboratory';
    case ONLINE_PHARMACY = 'online_pharmacy';

    public function label(): string
    {
        return match ($this) {
            self::LABORATORY => 'Laboratorio',
            self::ONLINE_PHARMACY => 'Farmacia en línea',
        };
    }
}
