<?php

namespace App\Enums;

enum PromoType: string
{
    case Individual = 'individual';
    case Shared = 'shared';
    case Influencer = 'influencer';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Shared => 'Compartido',
            self::Influencer => 'Influencer',
            self::Event => 'Evento',
        };
    }
}
