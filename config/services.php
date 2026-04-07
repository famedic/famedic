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
        'pixel_id' => env('FB_PIXEL_ID'),
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
        // Cola de jobs (database/redis). Por defecto "default". En local puede ser "murguia" y usar: php artisan queue:work --queue=murguia
        'queue' => env('MURGUIA_QUEUE_NAME', 'default'),
    ],

    'murguia_web_affiliate' => [
        'token_url' => env('MURGUIA_WEB_AFFILIATE_TOKEN_URL', 'https://app.sistemaoperaciones.com/soaang-users/api/token/'),
        'iframe_base_url' => env('MURGUIA_WEB_AFFILIATE_IFRAME_BASE_URL', 'https://afiliado.sistemaoperaciones.com/soaang-web-affiliate/external-validation/'),
        'username' => env('MURGUIA_WEB_AFFILIATE_USERNAME', 'ODESSAMX'),
        'password' => env('MURGUIA_WEB_AFFILIATE_PASSWORD', '123456App&'),
        'ac_id' => (int) env('MURGUIA_WEB_AFFILIATE_AC_ID', 23),
        'pl_id' => (int) env('MURGUIA_WEB_AFFILIATE_PL_ID', 60),
        'client_id' => (int) env('MURGUIA_WEB_AFFILIATE_CLIENT_ID', 25),
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
    'activecampaign' => [
        'endpoint' => env('ACTIVE_CAMPAIGN_API_ENDPOINT'),
        'token' => env('ACTIVE_CAMPAIGN_API_TOKEN'),
        'account_id' => env('ACTIVE_CAMPAIGN_ACCOUNT_ID'),
        'event_key' => env('ACTIVE_CAMPAIGN_EVENT_KEY'),
        'list_new_users' => env('ACTIVE_CAMPAIGN_LIST_NEW_USERS'),
        'tag_registro_web' => env('ACTIVE_CAMPAIGN_TAG_REGISTRO_WEB'),
        'cart_abandoned_minutes' => (int) env('ACTIVE_CAMPAIGN_CART_ABANDONED_MINUTES', 60),
        'tag_abandoned_carts_enabled' => filter_var(
            env('ACTIVECAMPAIGN_TAG_ABANDONED_CARTS_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        'tag_pharmacy_purchase_completed' => (int) env('ACTIVE_CAMPAIGN_TAG_PHARMACY_PURCHASE_COMPLETED', 17),
        'tag_laboratory_purchase_completed' => (int) env('ACTIVE_CAMPAIGN_TAG_LABORATORY_PURCHASE_COMPLETED', 18),
        // Tags específicos laboratorio
        'tag_registro_nuevo' => (function () {
            $raw = env('ACTIVE_CAMPAIGN_TAG_REGISTRO_NUEVO');
            if (is_numeric($raw)) {
                return (int) $raw;
            }

            if (is_string($raw) && preg_match('/\d+/', $raw, $m)) {
                return (int) $m[0];
            }

            return 3; // RegistroNuevo
        })(),
        'tag_lab_sample_collected' => env('ACTIVE_CAMPAIGN_TAG_LAB_SAMPLE_COLLECTED', 32),
        'tag_lab_results_available' => env('ACTIVE_CAMPAIGN_TAG_LAB_RESULTS_AVAILABLE', 33),
    ],
];
