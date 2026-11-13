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
    ],
    'financial' => ['enabled' => false, 'certified' => false, 'legacy_writers_coordinated' => false],
];
