<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;

enum MarketingCampaignLinkStatus: string implements LabelledEnum
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'Activo',
            self::Paused => 'Pausado',
            self::Archived => 'Archivado',
        };
    }
}
