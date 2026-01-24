<?php

return [
    'api' => [
        'base_url' => env('ACTIVE_CAMPAIGN_API_BASE_URL'),
        'endpoint' => env('ACTIVE_CAMPAIGN_API_ENDPOINT'),
        'token' => env('ACTIVE_CAMPAIGN_API_TOKEN'),
    ],

    'lists' => [
        'default' => env('ACTIVE_CAMPAIGN_LIST_ID', 5),
    ],
    
    'tags' => [
        'registro_nuevo' => env('ACTIVE_CAMPAIGN_TAG_REGISTRO_NUEVO', 'RegistroNuevo'),
    ],

    'sync' => [
        'enabled' => env('ACTIVE_CAMPAIGN_SYNC_ENABLED', true),
        'use_queue' => env('ACTIVE_CAMPAIGN_QUEUE_SYNC', true),
        'queue_name' => 'activecampaign',
        'retry_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mapeo de campos personalizados CORREGIDO
    |--------------------------------------------------------------------------
    | Solo campos que NO están en los campos estándar de ActiveCampaign
    | Campos estándar: email, firstName, lastName, phone
    */
    'field_mapping' => [
        // Usa el campo 9 "Género" (text) en lugar del 2 "Sexo" (dropdown)
        'gender' => env('ACTIVE_CAMPAIGN_FIELD_GNERO'), // ID 9
        
        // Fecha de nacimiento
        'birth_date' => env('ACTIVE_CAMPAIGN_FIELD_FECHA_DE_NACIMIENTO'), // ID 3
        
        // Estado/Entidad Federativa
        'state' => env('ACTIVE_CAMPAIGN_FIELD_ENTIDAD_FEDERATIVA'), // ID 10
        
        // País del teléfono
        'phone_country' => env('ACTIVE_CAMPAIGN_FIELD_PAS_TELFONO'), // ID 8
        
        // Fecha de registro
        'created_at' => env('ACTIVE_CAMPAIGN_FIELD_FECHA_DE_REGISTRO'), // ID 6
        
        // Referido por
        'referred_by' => env('ACTIVE_CAMPAIGN_FIELD_REFERENCIADO_POR'), // ID 11
        
        // NO INCLUIR los apellidos - ya van en el campo estándar lastName
        // 'paternal_lastname' => env('ACTIVE_CAMPAIGN_FIELD_APELLIDO_PATERNO'), // ELIMINAR
        // 'maternal_lastname' => env('ACTIVE_CAMPAIGN_FIELD_APELLIDO_MATERNO'), // ELIMINAR
    ],
    
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry' => [
            'times' => 3,
            'sleep' => 100,
        ],
    ],
];