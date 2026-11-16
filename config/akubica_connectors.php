<?php

// No operative enablement from environment variables. Certification and explicit rollout required.
return [
    'first_party_purchase' => ['enabled' => false, 'certified' => false],
    'outbox' => ['enabled' => false, 'certified' => false],
    'catalog_limits' => ['assigned_sources' => 200, 'eligible_options' => 50],
    'contract' => ['accepted' => false],
    'benefits_api' => ['enabled' => false, 'certified' => false],
    'consent' => ['enabled' => false, 'certified' => false, 'text_version' => null],
    'providers' => ['paypal' => ['certified' => false], 'efevoopay' => ['certified' => false], 'odessa' => ['certified' => false]],
    'paypal_merchant_id' => null,
    'transport' => ['enabled' => true, 'certified' => true],
    'otp_enrollment' => ['enabled' => true, 'certified' => true],
    'continuity' => [
        'enabled' => false,
        'certified' => false,
        'ttl_minutes' => 10,
        'allowed_host' => env('AKUBICA_CONTINUITY_ALLOWED_HOST', null),
        // RC2 B24 (D2): Leo -> FAMEDIC membership checkout continuity. Off by default.
        // Single, explicit exception to the "no env enablement" rule above, authorized by Security for QA only:
        // it can only turn on when APP_ENV=staging AND AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED=true. Any other
        // environment (production included) stays off regardless of the variable. Kill switch: unset the variable
        // and run `php artisan config:cache` (already issued links stop being redeemable).
        'medical_services' => (static function (): array {
            $on = env('APP_ENV') === 'staging'
                && filter_var(env('AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED', false), FILTER_VALIDATE_BOOL);

            return ['enabled' => $on, 'certified' => $on];
        })(),
    ],
    'financial' => ['enabled' => false, 'certified' => false, 'legacy_writers_coordinated' => false],
];
