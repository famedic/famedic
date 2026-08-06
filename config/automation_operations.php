<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automation Platform — driver catalog (Operations Center)
    |--------------------------------------------------------------------------
    |
    | "active" drivers are currently wired in config/order_automation.php or
    | PaymentAutomation. "planned" drivers appear in the console as inactive
    | so the architecture is ready for Email / WhatsApp / Push / etc.
    |
    */

    'drivers' => [
        [
            'key' => 'activecampaign_payment',
            'name' => 'ActiveCampaignPaymentDriver',
            'class' => \App\Services\Payments\Drivers\ActiveCampaignPaymentDriver::class,
            'layer' => 'PaymentAutomation',
            'status' => 'active',
            'version' => '2D',
            'description' => 'Tag WA-PagoRechazado on declined / remove on approved',
        ],
        [
            'key' => 'activecampaign_order',
            'name' => 'ActiveCampaignOrderDriver',
            'class' => \App\Services\Orders\Drivers\ActiveCampaignOrderDriver::class,
            'layer' => 'OrderAutomation',
            'status' => 'active',
            'version' => '2E',
            'description' => 'Sync laboratory / pharmacy / membership orders to ActiveCampaign',
        ],
        [
            'key' => 'email_order',
            'name' => 'EmailOrderDriver',
            'class' => null,
            'layer' => 'OrderAutomation',
            'status' => 'planned',
            'version' => null,
            'description' => 'Transactional email after order completion',
        ],
        [
            'key' => 'whatsapp_order',
            'name' => 'WhatsAppOrderDriver',
            'class' => null,
            'layer' => 'OrderAutomation',
            'status' => 'planned',
            'version' => null,
            'description' => 'WhatsApp notifications for order outcomes',
        ],
        [
            'key' => 'sms_order',
            'name' => 'SmsOrderDriver',
            'class' => null,
            'layer' => 'OrderAutomation',
            'status' => 'planned',
            'version' => null,
            'description' => 'SMS notifications',
        ],
        [
            'key' => 'push_order',
            'name' => 'PushOrderDriver',
            'class' => null,
            'layer' => 'OrderAutomation',
            'status' => 'planned',
            'version' => null,
            'description' => 'Push notifications',
        ],
        [
            'key' => 'analytics',
            'name' => 'AnalyticsOrderDriver',
            'class' => null,
            'layer' => 'OrderAutomation',
            'status' => 'planned',
            'version' => null,
            'description' => 'Product analytics events',
        ],
        [
            'key' => 'timeline',
            'name' => 'TimelineDriver',
            'class' => null,
            'layer' => 'Platform',
            'status' => 'planned',
            'version' => null,
            'description' => 'Customer timeline writes',
        ],
        [
            'key' => 'ai_automation',
            'name' => 'AiAutomationDriver',
            'class' => null,
            'layer' => 'Platform',
            'status' => 'planned',
            'version' => null,
            'description' => 'AI-assisted post-order automations',
        ],
        [
            'key' => 'customer_journey',
            'name' => 'CustomerJourneyDriver',
            'class' => null,
            'layer' => 'Platform',
            'status' => 'planned',
            'version' => null,
            'description' => 'Customer Journey stage updates',
        ],
        [
            'key' => 'customer_health',
            'name' => 'CustomerHealthDriver',
            'class' => null,
            'layer' => 'Platform',
            'status' => 'planned',
            'version' => null,
            'description' => 'Customer Health score signals',
        ],
    ],

];
