<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;

return [
    'namespace' => AkubicaUatFixtureContract::NAMESPACE,
    'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
    'allowed_environments' => ['testing', 'staging'],
    'operator_id' => (int) env('AKUBICA_UAT_OPERATOR_ID', 0),
    'access_ttl_minutes' => (int) env('AKUBICA_UAT_ACCESS_TTL_MINUTES', 30),
    'ttl_days' => 14,
    'audit_retention_days' => 30,
    'storage' => [
        'disk' => AkubicaUatFixtureContract::STORAGE_DISK,
        'prefix' => AkubicaUatFixtureContract::STORAGE_PREFIX,
    ],
    // Optional release metadata file written by the deploy process; never use
    // the runner checkout HEAD as the deployed SHA source.
    'release_metadata_path' => env('AKUBICA_UAT_RELEASE_METADATA_PATH'),
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
        'membership_none' => ['email' => env('AKUBICA_UAT_MEMBERSHIP_NONE_EMAIL'), 'phone' => env('AKUBICA_UAT_MEMBERSHIP_NONE_PHONE'), 'country' => env('AKUBICA_UAT_MEMBERSHIP_NONE_PHONE_COUNTRY', 'MX')],
        'membership_regular' => ['email' => env('AKUBICA_UAT_MEMBERSHIP_REGULAR_EMAIL'), 'phone' => env('AKUBICA_UAT_MEMBERSHIP_REGULAR_PHONE'), 'country' => env('AKUBICA_UAT_MEMBERSHIP_REGULAR_PHONE_COUNTRY', 'MX')],
        'membership_institutional' => ['email' => env('AKUBICA_UAT_MEMBERSHIP_INSTITUTIONAL_EMAIL'), 'phone' => env('AKUBICA_UAT_MEMBERSHIP_INSTITUTIONAL_PHONE'), 'country' => env('AKUBICA_UAT_MEMBERSHIP_INSTITUTIONAL_PHONE_COUNTRY', 'MX')],
        'membership_expired' => ['email' => env('AKUBICA_UAT_MEMBERSHIP_EXPIRED_EMAIL'), 'phone' => env('AKUBICA_UAT_MEMBERSHIP_EXPIRED_PHONE'), 'country' => env('AKUBICA_UAT_MEMBERSHIP_EXPIRED_PHONE_COUNTRY', 'MX')],
    ],
];
