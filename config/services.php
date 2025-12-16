<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'pixel_id'   => env('FB_PIXEL_ID'),
        'capi_token' => env('FB_CAPI_TOKEN'),
        'test_event_code' => env('FB_TEST_EVENT_CODE'),
        'batch_size_limit' => env('FB_BATCH_SIZE_LIMIT', 10),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET')
    ],

    'vitau' => [
        'url' => env('VITAU_URL'),
        'key' => env('VITAU_API_KEY'),
        'email' => env('VITAU_EMAIL'),
        'password' => env('VITAU_PASSWORD'),
        'payment_method' => env('VITAU_PAYMENT_METHOD'),
        'report_emails' => env('VITAU_REPORT_EMAILS') ? explode(',', env('VITAU_REPORT_EMAILS')) : [],
    ],

    'murguia' => [
        'url' => env('MURGUIA_URL'),
        'username' => env('MURGUIA_USERNAME'),
        'password' => env('MURGUIA_PASSWORD'),
    ],

    'gda' => [
        'url' => env('GDA_URL'),        
        'report_emails' => env('GDA_REPORT_EMAILS') ? explode(',', env('GDA_REPORT_EMAILS')) : [],
        'concierge_emails' => env('GDA_CONCIERGE_EMAILS') ? explode(',', env('GDA_CONCIERGE_EMAILS')) : [],
        'brands' => [
            'swisslab' => [
                'brand_id' => env('GDA_SWISSLAB_ID'),
                'brand_agreement_id' => env('GDA_SWISSLAB_AGREEMENT_ID'),
                'token' => env('GDA_SWISSLAB_TOKEN'),
            ],
            'olab' => [
                'brand_id' => env('GDA_OLAB_ID'),
                'brand_agreement_id' => env('GDA_OLAB_AGREEMENT_ID'),
                'token' => env('GDA_OLAB_TOKEN'),
            ],
            'azteca' => [
                'brand_id' => env('GDA_AZTECA_ID'),
                'brand_agreement_id' => env('GDA_AZTECA_AGREEMENT_ID'),
                'token' => env('GDA_AZTECA_TOKEN'),
            ],
            'jenner' => [
                'brand_id' => env('GDA_JENNER_ID'),
                'brand_agreement_id' => env('GDA_JENNER_AGREEMENT_ID'),
                'token' => env('GDA_JENNER_TOKEN'),
            ],
            'liacsa' => [
                'brand_id' => env('GDA_LIACSA_ID'),
                'brand_agreement_id' => env('GDA_LIACSA_AGREEMENT_ID'),
                'token' => env('GDA_LIACSA_TOKEN'),
            ],
            'famedic' => [
                'brand_id' => env('GDA_BRANDS_FAMEDIC_BRAND_ID'),
                'token' => env('GDA_BRANDS_FAMEDIC_TOKEN'),
                'brand_agreement_id' => env('GDA_BRANDS_FAMEDIC_BRAND_AGREEMENT_ID'),
            ],
        ]
    ],

    'odessa' => [
        'url' => env('ODESSA_URL'),
        'refund_report_emails' => env('ODESSA_REFUND_REPORT_EMAILS') ? explode(',', env('ODESSA_REFUND_REPORT_EMAILS')) : [],
    ],

    'recaptcha' => [
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
    ],
];
