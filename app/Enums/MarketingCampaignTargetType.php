<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;

enum MarketingCampaignTargetType: string implements LabelledEnum
{
    case Brand = 'brand';
    case Category = 'category';
    case Product = 'product';
    case Collection = 'collection';

    public function label(): string
    {
        return match ($this) {
            self::Brand => 'Marca',
            self::Category => 'Categoría',
            self::Product => 'Producto',
            self::Collection => 'Colección',
        };
    }
}
