<?php

namespace App\Services\Otp\Registration;

/**
 * Central evaluation of Akubica secure-register flags and policy (P0-A5.2).
 *
 * Distinguishes: configured flag · dependencies met · patient-ready (always false here).
 */
final class AkubicaRegistrationPolicy
{
    public const PURPOSE = 'akubica_register';

    public static function isEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.akubica_register_enabled', false);
    }

    public static function infrastructureEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.infrastructure_enabled', false);
    }

    public static function antiAbuseEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.anti_abuse_enabled', false);
    }

    public static function dependenciesSatisfied(): bool
    {
        return self::infrastructureEnabled() && self::antiAbuseEnabled();
    }

    /**
     * Flag ON and infrastructure+anti_abuse ON. Still not patient-ready without delivery/wiring.
     */
    public static function canOperate(): bool
    {
        return self::isEnabled() && self::dependenciesSatisfied();
    }

    /**
     * Always false in P0-A5.2: no delivery, no intents, no verify provisioning.
     */
    public static function isPatientReady(): bool
    {
        return false;
    }

    public static function deliveryEnabled(): bool
    {
        return (bool) config('otp.p0a.registration.delivery_enabled', false);
    }

    public static function ttlMinutes(): int
    {
        return (int) config('otp.p0a.registration.ttl_minutes', 10);
    }

    public static function codeLength(): int
    {
        return (int) config('otp.p0a.registration.length', 6);
    }

    public static function maxAttempts(): int
    {
        return (int) config('otp.p0a.registration.max_attempts', 5);
    }

    public static function cooldownSeconds(): int
    {
        return (int) config('otp.p0a.registration.cooldown_seconds', 60);
    }

    public static function maxResends(): int
    {
        return (int) config('otp.p0a.registration.max_resends', 3);
    }

    public static function purpose(): string
    {
        return (string) config('otp.p0a.registration.purpose', self::PURPOSE);
    }

    public static function channel(): string
    {
        return (string) config('otp.p0a.registration.channel', 'email');
    }
}
