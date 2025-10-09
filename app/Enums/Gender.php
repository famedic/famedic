<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;
use App\Traits\HasCasesWithLabels;

enum Gender: int implements LabelledEnum
{
    use HasCasesWithLabels;

    case MALE  = 1;
    case FEMALE = 2;

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Masculino',
            self::FEMALE => 'Femenino',
        };
    }
}
