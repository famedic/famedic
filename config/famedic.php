<?php

return [
    'permissions' => [
        'administrators' => [
            ['manage' => 'Administrar administradores, roles y permisos'],
        ],
        'customers' => [
            ['manage' => 'Administrar clientes'],
            ['manage.export' => 'Descargar clientes'],
        ],
        'documentation' => [
            ['manage' => 'Administrar documentación'],
        ],
        'laboratory-purchases' => [
            ['manage' => 'Administrar ordenes de laboratorio'],
            ['manage.invoices' => 'Subir y actualizar facturas'],
            ['manage.results' => 'Subir y actualizar resultados'],
            ['manage.cancel' => 'Cancelar ordenes'],
            ['manage.export' => 'Descargar ordenes'],
            ['manage.vendor-payments' => 'Gestionar pagos a proveedor'],

        ],
        'laboratory-tests' => [
            ['manage' => 'Administrar catálogo de laboratorio'],
            ['manage.edit' => 'Editar catálogo de laboratorio'],
        ],
        'online-pharmacy-purchases' => [
            ['manage' => 'Administrar ordenes de farmacia en línea'],
            ['manage.invoices' => 'Subir y actualizar facturas'],
            ['manage.cancel' => 'Cancelar ordenes'],
            ['manage.export' => 'Descargar ordenes'],
            ['manage.vendor-payments' => 'Gestionar pagos a proveedor'],
        ],
        'medical-attention-subscriptions' => [
            ['manage' => 'Administrar membresías médicas'],
            ['manage.export' => 'Descargar membresías médicas'],
        ],
        'subscription-invoices' => [
            ['manage' => 'Administrar membresías médicas'],
            ['manage.invoices' => 'Subir y actualizar facturas'],
        ],
        'laboratory-notifications' => [
            ['monitor' => 'Monitorear notificaciones de laboratorio'],
        ],
        'logs-general' => [
            ['manage' => 'Ver y descargar logs'],
        ],
        'users' => [
            ['manage' => 'Ver listado y detalle de usuarios'],
        ],
        'efevoo-tokens' => [
            ['manage' => 'Ver tokens de Efevoo'],
        ],
        'tax-profiles' => [
            ['manage' => 'Monitorear perfiles fiscales'],
        ],
        'payment-attempts' => [
            ['manage' => 'Monitorear intentos de pago'],
        ],
        'config_monitor' => [
            ['manage_metadata' => 'Administrar metadatos del monitor de configuración'],
        ],
    ],

    'medical_attention_subscription_price_cents' => 30000,
    'free_medical_attention_subscription_days' => 30,

    /** Licencia institucional Odessa (monitor admin / alta manual) */
    'institutional_odessa_subscription_years' => (int) env('INSTITUTIONAL_ODESSA_SUBSCRIPTION_YEARS', 1),
    'institutional_odessa_subscription_price_cents' => (int) env('INSTITUTIONAL_ODESSA_SUBSCRIPTION_PRICE_CENTS', 0),
    /** Solo entornos controlados: permite activar licencia institucional aunque el morph no sea Odessa (p. ej. cuenta mal clasificada como Regular) */
    'murguia_institutional_allow_non_odessa_morph' => filter_var(
        env('MURGUIA_INSTITUTIONAL_ALLOW_NON_ODESSA', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    'storage_paths' => [
        'laboratory_purchase_pdfs' => env('LABORATORY_PURCHASE_PDFS_PATH', 'pdfs/laboratory-purchases'),
    ],
];
