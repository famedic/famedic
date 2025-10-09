<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;
use App\Traits\HasCasesWithLabels;

enum MedicalSubscriptionType: string implements LabelledEnum
{
    use HasCasesWithLabels;

    case TRIAL = 'trial';
    case REGULAR = 'regular';
    case INSTITUTIONAL = 'institutional';
    case FAMILY_MEMBER = 'family_member';

    public function label(): string
    {
        return match ($this) {
            self::TRIAL => 'Prueba gratuita',
            self::REGULAR => 'Regular',
            self::INSTITUTIONAL => 'Institucional',
            self::FAMILY_MEMBER => 'Miembro familiar',
        };
    }
}