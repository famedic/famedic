<?php

namespace App\Enums;

enum ActiveCampaignSiteEvent: string
{
    case CartAbandoned = 'famedic_cart_abandoned';
    case CartResumed = 'famedic_cart_resumed';
    case CartRecovered = 'famedic_cart_recovered';

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom($name);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public function configKey(): string
    {
        return match ($this) {
            self::CartAbandoned => 'abandoned',
            self::CartResumed => 'resumed',
            self::CartRecovered => 'recovered',
        };
    }

    public function resolvedName(): string
    {
        $configured = config('services.activecampaign.site_events.cart.'.$this->configKey());

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : $this->value;
    }
}
