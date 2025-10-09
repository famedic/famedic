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
    ],

    'medical_attention_subscription_price_cents' => 30000,
    'free_medical_attention_subscription_days' => 30,

    'storage_paths' => [
        'laboratory_purchase_pdfs' => env('LABORATORY_PURCHASE_PDFS_PATH', 'pdfs/laboratory-purchases'),
    ],
];
