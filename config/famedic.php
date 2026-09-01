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

    /*
    |--------------------------------------------------------------------------
    | Portal de usuario — Soporte y contacto público
    |--------------------------------------------------------------------------
    */
    'support' => [
        'customer_service' => [
            'title' => 'Atención a clientes',
            'contact_name' => 'Lydia',
            'whatsapp_display' => env('FAMEDIC_SUPPORT_LYDIA_DISPLAY', '+52 1 81 2860 1893'),
            'whatsapp_e164' => env('FAMEDIC_SUPPORT_LYDIA_WHATSAPP_E164', '528128601893'),
            'whatsapp_default_message' => 'Hola, necesito ayuda con mi cuenta o una compra en Famedic.',
        ],
        'alternative_channel' => [
            'title' => 'Canal alternativo de atención',
            'badge' => 'Segundo canal',
            'description' => 'Otra opción para comunicarte con Famedic y recibir orientación.',
            'button_label' => 'Abrir WhatsApp alternativo',
            'whatsapp_display' => env('FAMEDIC_SUPPORT_ALTERNATIVE_WHATSAPP_DISPLAY', '+52 1 33 4960 5998'),
            'whatsapp_e164' => env('FAMEDIC_SUPPORT_ALTERNATIVE_WHATSAPP_E164', '523349605998'),
            'whatsapp_default_message' => 'Hola, quiero recibir información y ayuda sobre los servicios de Famedic.',
        ],
        'email' => [
            'address' => env('FAMEDIC_SUPPORT_EMAIL', 'contacto@famedic.com.mx'),
            'subject' => 'Solicitud de soporte Famedic',
        ],
        'hours' => [
            'timezone' => env('FAMEDIC_SUPPORT_TIMEZONE', 'America/Monterrey'),
            'timezone_label' => 'Horario de Monterrey',
            'schedule_by_day' => [
                0 => null,
                1 => ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60],
                2 => ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60],
                3 => ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60],
                4 => ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60],
                5 => ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60],
                6 => null,
            ],
            'available_message' => 'Nuestro equipo está disponible. La respuesta estimada es inmediata.',
            'after_hours_message' => 'Puedes enviarnos tu mensaje y nuestro equipo te responderá durante el siguiente horario hábil.',
        ],
        'appointment_confirmation' => [
            'text' => 'Nuestro equipo Concierge te contactará por teléfono para confirmar la fecha, el horario y la sucursal de tu cita.',
            'companion_text' => 'Concierge Famedic te acompaña durante la coordinación de tu cita y te ayuda a confirmar los detalles necesarios antes de continuar con el proceso.',
        ],
    ],

    /**
     * Concierge Famedic — citas de laboratorio (checkout y portal de soporte).
     * Fuente compartida con getConciergeAvailability en frontend.
     */
    'concierge' => [
        'phone_display' => env('FAMEDIC_CONCIERGE_PHONE_DISPLAY', '(55) 6651 5232'),
        'phone_tel' => env('FAMEDIC_CONCIERGE_PHONE_TEL', '5566515232'),
        'timezone' => env('FAMEDIC_CONCIERGE_TIMEZONE', 'America/Mexico_City'),
        'schedule_lines' => [],
        'schedule_by_day' => [
            0 => ['openMinutes' => 8 * 60, 'closeMinutes' => 14 * 60],
            1 => ['openMinutes' => 7 * 60, 'closeMinutes' => 20 * 60],
            2 => ['openMinutes' => 7 * 60, 'closeMinutes' => 20 * 60],
            3 => ['openMinutes' => 7 * 60, 'closeMinutes' => 20 * 60],
            4 => ['openMinutes' => 7 * 60, 'closeMinutes' => 20 * 60],
            5 => ['openMinutes' => 7 * 60, 'closeMinutes' => 20 * 60],
            6 => ['openMinutes' => 8 * 60, 'closeMinutes' => 15 * 60],
        ],
        'availability' => [
            'online_label' => 'Concierge en línea',
            'online_message' => 'Nuestro equipo está disponible ahora para ayudarte a agendar tu cita.',
            'offline_label' => 'Concierge fuera de horario',
            'offline_message' => 'Nuestro equipo podrá ayudarte en el siguiente horario disponible.',
        ],
        'checkout_offline_messages' => [
            'Ahora no estamos disponibles por teléfono.',
            'Puedes dejar tu solicitud y te llamaremos en el siguiente horario disponible.',
        ],
        'after_hours_message' => 'Puedes continuar con tu solicitud y nuestro equipo Concierge se comunicará contigo por teléfono durante el siguiente horario de atención.',
        'available_message' => 'Nuestro equipo está disponible. La respuesta estimada es inmediata.',
        'description' => 'Concierge Famedic coordina y confirma por teléfono la fecha, el horario y la sucursal de tu cita de laboratorio.',
    ],

    'social' => [
        'intro' => 'Conoce nuestras novedades, servicios y recomendaciones de salud.',
        'profiles' => [
            [
                'network' => 'Instagram',
                'url' => 'https://www.instagram.com/famedicmx/',
                'icon' => 'instagram',
            ],
            [
                'network' => 'Facebook',
                'url' => 'https://www.facebook.com/famedicmx/',
                'icon' => 'facebook',
            ],
            [
                'network' => 'LinkedIn',
                'url' => 'https://mx.linkedin.com/company/famedicmx',
                'icon' => 'linkedin',
            ],
        ],
    ],
];
