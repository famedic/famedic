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
        'odessa-reconciliation' => [
            ['view' => 'Ver historial y resultados de conciliaciones ODESSA'],
            ['manage' => 'Analizar y exportar conciliaciones ODESSA'],
            ['review' => 'Actualizar estados de revisión de conciliaciones ODESSA'],
            ['actions' => 'Ejecutar acciones desde conciliación ODESSA'],
            ['actions.email' => 'Ejecutar correcciones de email desde conciliación ODESSA'],
            ['actions.odessa' => 'Ejecutar correcciones de relación ODESSA desde conciliación'],
            ['actions.membership' => 'Ejecutar correcciones de membresía desde conciliación ODESSA'],
            ['actions.murguia' => 'Reintentar sincronizaciones Murguía desde conciliación ODESSA'],
        ],
        'odessa-pre-enrollments' => [
            ['view' => 'Ver preafiliaciones ODESSA'],
            ['manage' => 'Analizar preafiliaciones ODESSA'],
            ['actions' => 'Ejecutar acciones de preafiliaciones ODESSA'],
            ['actions.generate-credit' => 'Reservar noCredito para preafiliaciones ODESSA'],
            ['actions.import' => 'Importar preafiliaciones ODESSA analizadas'],
            ['actions.murguia-register' => 'Alta individual Murguía desde preafiliación ODESSA'],
            ['actions.murguia-verify' => 'Verificar estado Murguía de preafiliación ODESSA'],
            ['actions.murguia-retry' => 'Reintentar alta Murguía de preafiliación ODESSA'],
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
        'cupones' => [
            ['manage' => 'Gestionar créditos y asignaciones'],
            ['view' => 'Ver cupones'],
            ['create' => 'Crear cupones'],
            ['edit' => 'Editar cupones'],
            ['delete' => 'Eliminar cupones'],
            ['config' => 'Configurar cupones'],
        ],
        'coupons' => [
            ['manage' => 'Gestionar créditos y asignaciones'],
        ],
        'config_monitor' => [
            ['manage_metadata' => 'Administrar metadatos del monitor de configuración'],
        ],
        'simulators' => [
            ['manage' => 'Usar simuladores internos (OTP, etc.) sin afectar pacientes'],
        ],
        'monitoring-ai' => [
            ['manage' => 'Usar el asistente IA de monitoreo'],
        ],
        'activecampaign' => [
            ['manage' => 'Administrar módulo ActiveCampaign'],
        ],
        'automation' => [
            ['manage' => 'Monitorear Automation Operations Center'],
        ],
        /*
         * Permisos con nombres que no siguen category.action (legacy).
         * La clave del arreglo es el nombre exacto del permiso en BD.
         */
        '_absolute' => [
            ['view carts' => 'Ver listado de carritos (monitoreo)'],
            ['view cart details' => 'Ver detalle de un carrito'],
            ['view_config_monitor' => 'Ver monitor de configuración (solo lectura)'],
        ],
    ],

    'medical_attention_subscription_price_cents' => 30000,
    'free_medical_attention_subscription_days' => 30,
    'medical_attention_trial_enabled' => filter_var(
        env('MEDICAL_ATTENTION_TRIAL_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /** Licencia institucional Odessa (monitor admin / alta manual) */
    'institutional_odessa_subscription_years' => (int) env('INSTITUTIONAL_ODESSA_SUBSCRIPTION_YEARS', 1),
    'institutional_odessa_subscription_price_cents' => (int) env('INSTITUTIONAL_ODESSA_SUBSCRIPTION_PRICE_CENTS', 0),
    /** Solo entornos controlados: permite activar licencia institucional aunque el morph no sea Odessa (p. ej. cuenta mal clasificada como Regular) */
    'murguia_institutional_allow_non_odessa_morph' => filter_var(
        env('MURGUIA_INSTITUTIONAL_ALLOW_NON_ODESSA', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    'odessa_reconciliation_actions' => [
        'enabled' => filter_var(
            env('ODESSA_RECONCILIATION_ACTIONS_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    'odessa_pre_enrollments' => [
        'enabled' => filter_var(
            env('ODESSA_PRE_ENROLLMENTS_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'generate_credit_enabled' => filter_var(
            env('ODESSA_PRE_ENROLLMENTS_GENERATE_CREDIT_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'import_enabled' => filter_var(
            env('ODESSA_PRE_ENROLLMENTS_IMPORT_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'murguia_enabled' => filter_var(
            env('ODESSA_PRE_ENROLLMENTS_MURGUIA_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'murguia_retry_enabled' => filter_var(
            env('ODESSA_PRE_ENROLLMENTS_MURGUIA_RETRY_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'murguia_product' => env('ODESSA_PRE_ENROLLMENTS_MURGUIA_PRODUCT'),
        'murguia_subproduct' => env('ODESSA_PRE_ENROLLMENTS_MURGUIA_SUBPRODUCT'),
        'membership_starts_at' => env('ODESSA_PRE_ENROLLMENTS_MEMBERSHIP_STARTS_AT'),
        'membership_ends_at' => env('ODESSA_PRE_ENROLLMENTS_MEMBERSHIP_ENDS_AT'),
        'murguia_not_found_codes' => collect(explode(',', (string) env('ODESSA_PRE_ENROLLMENTS_MURGUIA_NOT_FOUND_CODES', '')))
            ->map(fn (string $code) => strtoupper(trim($code)))
            ->filter(fn (string $code) => preg_match('/^[A-Z0-9_:-]{2,64}$/', $code))
            ->values()
            ->all(),
        'murguia_lease_minutes' => (int) env('ODESSA_PRE_ENROLLMENTS_MURGUIA_LEASE_MINUTES', 5),
        'import_expected_sheet' => env('ODESSA_PRE_ENROLLMENTS_IMPORT_EXPECTED_SHEET', 'Sin Registro'),
        'import_max_sheets' => (int) env('ODESSA_PRE_ENROLLMENTS_IMPORT_MAX_SHEETS', 5),
        'import_max_rows' => (int) env('ODESSA_PRE_ENROLLMENTS_IMPORT_MAX_ROWS', 1000),
        'import_max_columns' => (int) env('ODESSA_PRE_ENROLLMENTS_IMPORT_MAX_COLUMNS', 30),
        'import_max_file_kb' => (int) env('ODESSA_PRE_ENROLLMENTS_IMPORT_MAX_FILE_KB', 20480),
        'import_run_retention_days' => (int) env('ODESSA_PRE_ENROLLMENTS_IMPORT_RUN_RETENTION_DAYS', 90),
    ],

    'storage_paths' => [
        'laboratory_purchase_pdfs' => env('LABORATORY_PURCHASE_PDFS_PATH', 'pdfs/laboratory-purchases'),
    ],

    /**
     * Facturación administrativa de laboratorios.
     * Umbral de atraso en días naturales desde invoice_requests.created_at.
     * Las solicitudes completas (PDF+XML) nunca se consideran atrasadas.
     */
    'laboratory_billing' => [
        'invoice_delay_threshold_days' => (int) env('INVOICE_DELAY_THRESHOLD_DAYS', 3),
    ],

    /**
     * URL base (sin barra final) para imágenes públicas en correos, p. ej. /images/logo.png.
     * Por defecto https://famedic.com.mx para que los logos carguen desde producción aunque el envío
     * sea desde otro entorno. Sobrescribe con FAMEDIC_EMAIL_PUBLIC_URL si necesitas otro host.
     */
    'email_public_url' => rtrim((string) env('FAMEDIC_EMAIL_PUBLIC_URL', 'https://famedic.com.mx'), '/'),

    /** Menú admin: muestra "Créditos a favor" pero sin permitir navegación. */
    'admin_coupons_navigation_disabled' => env('ADMIN_COUPONS_NAVIGATION_DISABLED', true),
];
