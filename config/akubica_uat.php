<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;

return [
    'namespace' => AkubicaUatFixtureContract::NAMESPACE,
    'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
    'allowed_environments' => ['testing', 'staging'],
    'ttl_days' => 14,
    'audit_retention_days' => 30,
    'storage' => [
        'disk' => AkubicaUatFixtureContract::STORAGE_DISK,
        'prefix' => AkubicaUatFixtureContract::STORAGE_PREFIX,
    ],
    'identities' => [
        'primary' => [
            'email' => env('AKUBICA_UAT_PRIMARY_EMAIL'),
            'phone' => env('AKUBICA_UAT_PRIMARY_PHONE'),
            'country' => env('AKUBICA_UAT_PRIMARY_PHONE_COUNTRY', 'MX'),
        ],
        'foreign' => [
            'email' => env('AKUBICA_UAT_FOREIGN_EMAIL'),
            'phone' => env('AKUBICA_UAT_FOREIGN_PHONE'),
            'country' => env('AKUBICA_UAT_FOREIGN_PHONE_COUNTRY', 'MX'),
        ],
        'disposable' => [
            'email' => env('AKUBICA_UAT_DISPOSABLE_EMAIL'),
            'phone' => env('AKUBICA_UAT_DISPOSABLE_PHONE'),
            'country' => env('AKUBICA_UAT_DISPOSABLE_PHONE_COUNTRY', 'MX'),
        ],
    ],
];
