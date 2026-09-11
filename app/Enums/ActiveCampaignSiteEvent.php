<?php

namespace App\Enums;

enum ActiveCampaignSiteEvent: string
{
    case CartAbandoned = 'famedic_cart_abandoned';
    case CartResumed = 'famedic_cart_resumed';
    case CartRecovered = 'famedic_cart_recovered';
    case AppointmentPending5m = 'famedic_appointment_pending_5m';
    case AppointmentConfirmed = 'famedic_appointment_confirmed';
    case CallRequested = 'famedic_call_requested';
    case CallAttempted = 'famedic_call_attempted';

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

    public function configPath(): string
    {
        return match ($this) {
            self::CartAbandoned => 'cart.abandoned',
            self::CartResumed => 'cart.resumed',
            self::CartRecovered => 'cart.recovered',
            self::AppointmentPending5m => 'appointment.pending_5m',
            self::AppointmentConfirmed => 'appointment.confirmed',
            self::CallRequested => 'call.requested',
            self::CallAttempted => 'call.attempted',
        };
    }

    /** @deprecated Use configPath() */
    public function configKey(): string
    {
        return $this->configPath();
    }

    public function resolvedName(): string
    {
        $segments = explode('.', $this->configPath());
        $value = config('services.activecampaign.site_events');

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $this->value;
            }

            $value = $value[$segment];
        }

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $this->value;
    }
}
