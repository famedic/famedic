<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;

enum MarketingCampaignStatus: string implements LabelledEnum
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Finished = 'finished';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Scheduled => 'Programada',
            self::Active => 'Activa',
            self::Paused => 'Pausada',
            self::Finished => 'Finalizada',
            self::Archived => 'Archivada',
        };
    }
}
