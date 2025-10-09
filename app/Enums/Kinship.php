<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;
use App\Traits\HasCasesWithLabels;

enum Kinship: string implements LabelledEnum
{
    use HasCasesWithLabels;

    case SPOUSE = 'spouse';
    case CHILD = 'child';
    case PARENT = 'parent';

    public function label(): string
    {
        return match ($this) {
            self::SPOUSE => 'Cónyuge',
            self::CHILD => 'Hijo/Hija',
            self::PARENT => 'Padre/Madre',
        };
    }
}
