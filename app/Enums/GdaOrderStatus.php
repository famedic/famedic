<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;

enum GdaOrderStatus: string implements LabelledEnum
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Confirmed => 'Confirmado',
            self::Failed => 'Fallido',
            self::Uncertain => 'Por validar',
        };
    }
}
