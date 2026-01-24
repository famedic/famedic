<?php
// test_activecampaign_direct.php
// Ejecutar: php test_activecampaign_direct.php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "⚡ PRUEBA DIRECTA SIN BASE DE DATOS - ACTIVECAMPAIGN\n\n";

// ============================================
// 1. DATOS DE PRUEBA (Array directo - sin DB)
// ============================================
$testData = [
    'name' => 'Prueba',
    'paternal_lastname' => 'Directa',
    'maternal_lastname' => 'Script',
    'email' => 'prueba_directa_' . time() . '@famedic.com',
    'phone' => '+521111222233',
    'phone_country' => 'MX',
    'birth_date' => '1995-05-15',
    'gender' => 2, // 1=Masculino, 2=Femenino
    'state' => 'NL',
    'created_at' => date('Y-m-d'),
];

echo "📋 Datos de prueba:\n";
foreach ($testData as $key => $value) {
    echo "  {$key}: {$value}\n";
}
echo "\n";

// ============================================
// 2. INICIALIZAR SERVICIO
// ============================================
$service = app(App\Services\ActiveCampaignService::class);

echo "🔌 Probando conexión... ";
if ($service->testConnection()) {
    echo "✅ OK\n";
} else {
    echo "❌ FALLÓ\n";
    exit(1);
}

// ============================================
// 3. PREPARAR DATOS MANUALMENTE
// ============================================
echo "\n🛠️  Preparando datos para ActiveCampaign...\n";

// 3.1 Datos básicos del contacto
$contactData = [
    'email' => $testData['email'],
    'first_name' => $testData['name'],
    'last_name' => $testData['paternal_lastname'] . ' ' . $testData['maternal_lastname'],
    'phone' => $testData['phone'],
];

echo "  Datos básicos del contacto:\n";
echo "  - Email: {$contactData['email']}\n";
echo "  - Nombre: {$contactData['first_name']}\n";
echo "  - Apellido: {$contactData['last_name']}\n";
echo "  - Teléfono: {$contactData['phone']}\n";

// 3.2 Preparar campos personalizados MANUALMENTE
$customFields = [];

// Mapeo manual de campos según tu configuración
$fieldMapping = config('activecampaign.field_mapping', []);

echo "\n  Mapeo de campos personalizados:\n";
foreach ($fieldMapping as $field => $fieldId) {
    if (!$fieldId) {
        echo "  ⚠️  Campo '{$field}' sin ID configurado\n";
        continue;
    }

    $value = null;

    switch ($field) {
        case 'gender':
            $value = ($testData['gender'] == 1) ? 'Masculino' : 'Femenino';
            break;
            
        case 'birth_date':
            $value = $testData['birth_date'];
            break;
            
        case 'state':
            // Convertir código de estado a nombre completo
            $stateCode = $testData['state'];
            $stateNames = [
                'NL' => 'Nuevo León',
                'DF' => 'Ciudad de México',
                'AG' => 'Aguascalientes',
                'BC' => 'Baja California',
                'BS' => 'Baja California Sur',
                'CM' => 'Campeche',
                'CS' => 'Chiapas',
                'CH' => 'Chihuahua',
                'CO' => 'Coahuila',
                'CL' => 'Colima',
                'DG' => 'Durango',
                'GT' => 'Guanajuato',
                'GR' => 'Guerrero',
                'HG' => 'Hidalgo',
                'JC' => 'Jalisco',
                'MX' => 'Estado de México',
                'MI' => 'Michoacán',
                'MO' => 'Morelos',
                'NA' => 'Nayarit',
                'OA' => 'Oaxaca',
                'PU' => 'Puebla',
                'QT' => 'Querétaro',
                'QR' => 'Quintana Roo',
                'SL' => 'San Luis Potosí',
                'SI' => 'Sinaloa',
                'SO' => 'Sonora',
                'TB' => 'Tabasco',
                'TM' => 'Tamaulipas',
                'TL' => 'Tlaxcala',
                'VE' => 'Veracruz',
                'YU' => 'Yucatán',
                'ZA' => 'Zacatecas',
            ];
            $value = $stateNames[$stateCode] ?? $stateCode;
            break;
            
        case 'phone_country':
            $value = $testData['phone_country'];
            break;
            
        case 'created_at':
            $value = $testData['created_at'];
            break;
            
        case 'paternal_lastname':
            $value = $testData['paternal_lastname'];
            break;
            
        case 'maternal_lastname':
            $value = $testData['maternal_lastname'];
            break;
            
        case 'referred_by':
            // No aplica para prueba
            break;
            
        default:
            $value = $testData[$field] ?? null;
            break;
    }

    if ($value !== null && $value !== '') {
        $customFields[$fieldId] = (string) $value;
        echo "  ✅ Campo {$field} -> ID {$fieldId}: '{$value}'\n";
    }
}

echo "\n  Total campos personalizados: " . count($customFields) . "\n";

// ============================================
// 4. SINCRONIZAR CON ACTIVECAMPAIGN
// ============================================
echo "\n🚀 Enviando datos a ActiveCampaign...\n";

$listId = config('activecampaign.lists.default', 5);
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'PruebaDirecta'];

echo "  Lista ID: {$listId}\n";
echo "  Tags: " . implode(', ', $tags) . "\n";

$result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);

if ($result['success']) {
    echo "✅ Sincronización exitosa!\n";
    echo "  Contacto ID: {$result['contact_id']}\n";
    echo "  Acción: {$result['action']}\n";
    
    // ============================================
    // 5. VERIFICAR RESULTADOS
    // ============================================
    echo "\n🔍 Verificando campos en ActiveCampaign...\n";
    
    $contactId = $result['contact_id'];
    sleep(3); // Esperar a que la API procese
    
    // Usar reflexión para acceder al cliente HTTP
    $reflection = new ReflectionClass($service);
    $clientProperty = $reflection->getProperty('client');
    $clientProperty->setAccessible(true);
    $client = $clientProperty->getValue($service);
    
    echo "\n  Campos estándar:\n";
    echo "  - Email: {$contactData['email']}\n";
    echo "  - Nombre: {$contactData['first_name']}\n";
    echo "  - Apellido: {$contactData['last_name']}\n";
    echo "  - Teléfono: {$contactData['phone']}\n";
    
    // Verificar campos personalizados uno por uno
    if (!empty($customFields)) {
        echo "\n  Campos personalizados verificados:\n";
        
        foreach ($customFields as $fieldId => $expectedValue) {
            echo "  Verificando campo ID {$fieldId}... ";
            
            // Buscar fieldValue directamente
            $searchResponse = $client->get("/api/3/fieldValues", [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
            ]);
            
            if ($searchResponse->successful()) {
                $data = $searchResponse->json();
                $actualValue = !empty($data['fieldValues']) ? $data['fieldValues'][0]['value'] ?? 'null' : 'null';
                
                if ($actualValue === $expectedValue) {
                    echo "✅ OK\n";
                } else {
                    echo "❌ FALLÓ\n";
                    echo "    Esperado: '{$expectedValue}'\n";
                    echo "    Obtenido: '{$actualValue}'\n";
                    
                    // Intentar actualizar directamente
                    echo "    Intentando corrección... ";
                    $updateResponse = $client->post('/api/3/fieldValues', [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => $expectedValue
                        ]
                    ]);
                    
                    if ($updateResponse->successful() || $updateResponse->status() === 422) {
                        echo "✅ Enviado (se actualizará)\n";
                    } else {
                        echo "❌ Error HTTP: " . $updateResponse->status() . "\n";
                    }
                }
            } else {
                echo "❌ Error API: " . $searchResponse->status() . "\n";
            }
            
            usleep(200000); // 0.2 segundos entre verificaciones
        }
    }
    
    // ============================================
    // 6. MOSTRAR TODOS LOS FIELDVALUES ACTUALES
    // ============================================
    echo "\n📊 Todos los fieldValues del contacto:\n";
    
    $response = $client->get("/api/3/contacts/{$contactId}/fieldValues");
    if ($response->successful()) {
        $data = $response->json();
        $fieldValues = $data['fieldValues'] ?? $data;
        
        if (is_array($fieldValues) && !empty($fieldValues)) {
            foreach ($fieldValues as $fv) {
                if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                    $fieldName = match($fv['field']) {
                        3 => 'Fecha Nacimiento',
                        4 => 'Apellido Paterno',
                        5 => 'Apellido Materno',
                        6 => 'Fecha Registro',
                        8 => 'País Teléfono',
                        9 => 'Género',
                        10 => 'Entidad Federativa',
                        11 => 'Referenciado Por',
                        default => "Campo {$fv['field']}"
                    };
                    echo "  - {$fieldName} (ID {$fv['field']}): '{$fv['value']}'\n";
                }
            }
        } else {
            echo "  - No hay fieldValues\n";
        }
    }
    
    // ============================================
    // 7. ENLACE DIRECTO A ACTIVECAMPAIGN
    // ============================================
    $baseUrl = config('activecampaign.api.base_url');
    if ($baseUrl) {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $account = explode('.', $domain)[0] ?? 'tu-cuenta';
        echo "\n🔗 Enlace directo al contacto:\n";
        echo "  https://{$account}.activehosted.com/app/contacts/{$contactId}\n";
        
        echo "\n📧 Datos de prueba para verificar manualmente:\n";
        echo "  Email: {$contactData['email']}\n";
        echo "  Contraseña: (no aplica - solo prueba)\n";
    }
    
} else {
    echo "❌ Error en sincronización:\n";
    echo "  {$result['error']}\n";
}

// ============================================
// 8. FINALIZAR
// ============================================
echo "\n🏁 Prueba completada.\n";
echo "⚠️  NOTA: Este script NO creó ningún usuario en la base de datos.\n";
echo "   Los datos fueron enviados directamente a ActiveCampaign.\n\n";


en la columna de apellidos si se cargaron los datos 


pero en la columna individual de apellido paterno y materno no se cargaron los datos 

en la columna genero si se cargo el dato pero en la columna individual de sexo no se cargo el dato