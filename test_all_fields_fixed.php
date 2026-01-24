<?php
// test_all_fields_fixed.php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🎯 PRUEBA TODOS LOS CAMPOS - ACTIVECAMPAIGN\n\n";

// ============================================
// 1. DATOS DE PRUEBA
// ============================================
$testData = [
    'name' => 'Prueba',
    'paternal_lastname' => 'Directa',
    'maternal_lastname' => 'Script',
    'email' => 'prueba_todos_' . time() . '@famedic.com',
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
// 3. PREPARAR DATOS PARA TODOS LOS CAMPOS
// ============================================
echo "\n🛠️  Preparando datos para TODOS los campos...\n";

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

// 3.2 Preparar TODOS los campos personalizados que existen en tu ActiveCampaign
$customFields = [];

// Lista COMPLETA de campos personalizados según lo que viste en tinker:
// ID 2: "Sexo" (dropdown) - ESTE es el que falta
// ID 3: "Fecha de Nacimiento"
// ID 4: "Apellido paterno"
// ID 5: "Apellido materno"
// ID 6: "Fecha de registro"
// ID 8: "País Teléfono"
// ID 9: "Género" (texto) - ESTE es el que sí se llena
// ID 10: "Entidad Federativa"
// ID 11: "Referenciado Por"

$allCustomFields = [
    2 => ['Sexo (dropdown)', $testData['gender'] == 1 ? 'Masculino' : 'Femenino'],
    3 => ['Fecha de Nacimiento', $testData['birth_date']],
    4 => ['Apellido paterno', $testData['paternal_lastname']],
    5 => ['Apellido materno', $testData['maternal_lastname']],
    6 => ['Fecha de registro', $testData['created_at']],
    8 => ['País Teléfono', $testData['phone_country']],
    9 => ['Género (texto)', $testData['gender'] == 1 ? 'Masculino' : 'Femenino'],
    10 => ['Entidad Federativa', 'Nuevo León'], // Convertir NL a nombre completo
    11 => ['Referenciado Por', ''], // Vacío para prueba
];

echo "\n  Campos personalizados a enviar:\n";
foreach ($allCustomFields as $fieldId => [$fieldName, $value]) {
    if (!empty($value)) {
        $customFields[$fieldId] = (string) $value;
        echo "  ✅ Campo ID {$fieldId} ({$fieldName}): '{$value}'\n";
    }
}

echo "\n  Total campos personalizados: " . count($customFields) . "\n";

// ============================================
// 4. SINCRONIZAR CON ACTIVECAMPAIGN
// ============================================
echo "\n🚀 Enviando datos a ActiveCampaign...\n";

$listId = config('activecampaign.lists.default', 5);
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'TestTodosCampos'];

echo "  Lista ID: {$listId}\n";
echo "  Tags: " . implode(', ', $tags) . "\n";

$result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);

if ($result['success']) {
    echo "✅ Sincronización exitosa!\n";
    echo "  Contacto ID: {$result['contact_id']}\n";
    echo "  Acción: {$result['action']}\n";
    
    // ============================================
    // 5. VERIFICAR RESULTADOS ESPECÍFICOS
    // ============================================
    echo "\n🔍 Verificando campos PROBLEMÁTICOS...\n";
    
    $contactId = $result['contact_id'];
    sleep(3); // Esperar a que la API procese
    
    // Usar reflexión para acceder al cliente HTTP
    $reflection = new ReflectionClass($service);
    $clientProperty = $reflection->getProperty('client');
    $clientProperty->setAccessible(true);
    $client = $clientProperty->getValue($service);
    
    // Campos específicos que sabemos que fallan
    $problematicFields = [
        2 => 'Sexo (dropdown)',
        4 => 'Apellido paterno',
        5 => 'Apellido materno',
    ];
    
    foreach ($problematicFields as $fieldId => $fieldName) {
        if (isset($customFields[$fieldId])) {
            $expectedValue = $customFields[$fieldId];
            
            echo "\n  🔎 Verificando {$fieldName} (ID {$fieldId})...\n";
            
            // Método 1: Buscar fieldValue con filtros
            $searchResponse = $client->get("/api/3/fieldValues", [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
            ]);
            
            if ($searchResponse->successful()) {
                $data = $searchResponse->json();
                $actualValue = !empty($data['fieldValues']) ? $data['fieldValues'][0]['value'] ?? 'null' : 'null';
                
                echo "    Busqueda con filtros: '{$actualValue}'\n";
                
                if ($actualValue === $expectedValue) {
                    echo "    ✅ Valor correcto\n";
                } else {
                    echo "    ❌ Valor incorrecto (esperado: '{$expectedValue}')\n";
                    
                    // Intentar actualizar con método directo
                    echo "    Intentando actualizar directamente...\n";
                    
                    // Primero intentar crear nuevo
                    $createResponse = $client->post('/api/3/fieldValues', [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => $expectedValue
                        ]
                    ]);
                    
                    if ($createResponse->successful()) {
                        echo "    ✅ Creado nuevo fieldValue\n";
                    } elseif ($createResponse->status() === 422) {
                        echo "    ⚠️  Ya existe, buscando para actualizar...\n";
                        
                        // Buscar todos los fieldValues del contacto
                        $allFieldValuesResponse = $client->get("/api/3/contacts/{$contactId}/fieldValues");
                        if ($allFieldValuesResponse->successful()) {
                            $allData = $allFieldValuesResponse->json();
                            $allFieldValues = $allData['fieldValues'] ?? $allData;
                            
                            if (is_array($allFieldValues)) {
                                foreach ($allFieldValues as $fv) {
                                    if (is_array($fv) && isset($fv['id'], $fv['field']) && (int)$fv['field'] === $fieldId) {
                                        // Actualizar el existente
                                        $updateResponse = $client->put("/api/3/fieldValues/{$fv['id']}", [
                                            'fieldValue' => [
                                                'contact' => $contactId,
                                                'field' => $fieldId,
                                                'value' => $expectedValue
                                            ]
                                        ]);
                                        
                                        if ($updateResponse->successful()) {
                                            echo "    ✅ Actualizado fieldValue existente (ID: {$fv['id']})\n";
                                        } else {
                                            echo "    ❌ Error actualizando: " . $updateResponse->status() . "\n";
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    } else {
                        echo "    ❌ Error creando: " . $createResponse->status() . "\n";
                    }
                }
            } else {
                echo "    ❌ Error en búsqueda: " . $searchResponse->status() . "\n";
            }
            
            usleep(300000); // 0.3 segundos
        }
    }
    
    // ============================================
    // 6. MOSTRAR TODOS LOS FIELDVALUES ACTUALES
    // ============================================
    echo "\n📊 TODOS los fieldValues del contacto:\n";
    
    $response = $client->get("/api/3/contacts/{$contactId}/fieldValues");
    if ($response->successful()) {
        $data = $response->json();
        $fieldValues = $data['fieldValues'] ?? $data;
        
        if (is_array($fieldValues) && !empty($fieldValues)) {
            $fieldNames = [
                2 => 'Sexo (dropdown)',
                3 => 'Fecha Nacimiento',
                4 => 'Apellido Paterno',
                5 => 'Apellido Materno',
                6 => 'Fecha Registro',
                8 => 'País Teléfono',
                9 => 'Género (texto)',
                10 => 'Entidad Federativa',
                11 => 'Referenciado Por',
            ];
            
            foreach ($fieldValues as $fv) {
                if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                    $fieldId = (int)$fv['field'];
                    $fieldName = $fieldNames[$fieldId] ?? "Campo {$fieldId}";
                    $value = $fv['value'];
                    $status = ($value === ($customFields[$fieldId] ?? '')) ? '✅' : '❌';
                    
                    echo "  {$status} {$fieldName} (ID {$fieldId}): '{$value}'\n";
                }
            }
        } else {
            echo "  - No hay fieldValues\n";
        }
    }
    
    // ============================================
    // 7. DIAGNÓSTICO: ¿Por qué fallan algunos campos?
    // ============================================
    echo "\n🔬 DIAGNÓSTICO DE PROBLEMAS:\n";
    
    // Posibles problemas:
    echo "1. ❌ Campo 'Sexo' (ID 2) es DROPDOWN:\n";
    echo "   - Puede requerir valores específicos (ej: 'Masculino', 'Femenino')\n";
    echo "   - Verifica las opciones del dropdown en ActiveCampaign\n\n";
    
    echo "2. ❌ Campos de apellidos (IDs 4 y 5):\n";
    echo "   - Puede haber conflicto con el campo estándar 'last_name'\n";
    echo "   - La API podría estar ignorando campos duplicados\n\n";
    
    echo "3. ✅ Campo 'Género' (ID 9) es TEXTO:\n";
    echo "   - Este sí funciona porque es campo de texto libre\n\n";
    
    // ============================================
    // 8. SOLUCIÓN: Probar con método ALTERNATIVO
    // ============================================
    echo "\n🔄 Probando método ALTERNATIVO para campos problemáticos...\n";
    
    $problemFields = [
        2 => ['Sexo', 'Masculino'],
        4 => ['Apellido Paterno', 'Directa'],
        5 => ['Apellido Materno', 'Script'],
    ];
    
    foreach ($problemFields as $fieldId => [$fieldName, $testValue]) {
        echo "  Probando {$fieldName} (ID {$fieldId}) con valor '{$testValue}'...\n";
        
        // Método 1: Usar PATCH en el contacto
        $patchResponse = $client->patch("/api/3/contacts/{$contactId}", [
            'contact' => [
                'fieldValues' => [
                    [
                        'field' => $fieldId,
                        'value' => $testValue
                    ]
                ]
            ]
        ]);
        
        if ($patchResponse->successful()) {
            echo "    ✅ PATCH exitoso\n";
        } else {
            echo "    ❌ PATCH falló: " . $patchResponse->status() . "\n";
            
            // Método 2: Usar el endpoint de fieldValues con método PUT directo
            echo "    Intentando PUT directo...\n";
            
            // Primero buscar el fieldValueId
            $searchResponse = $client->get("/api/3/fieldValues", [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
            ]);
            
            if ($searchResponse->successful()) {
                $data = $searchResponse->json();
                if (!empty($data['fieldValues'])) {
                    $fieldValueId = $data['fieldValues'][0]['id'];
                    
                    $putResponse = $client->put("/api/3/fieldValues/{$fieldValueId}", [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => $testValue
                        ]
                    ]);
                    
                    if ($putResponse->successful()) {
                        echo "    ✅ PUT directo exitoso\n";
                    } else {
                        echo "    ❌ PUT falló: " . $putResponse->status() . "\n";
                    }
                } else {
                    echo "    ℹ️  No existe fieldValue, creando nuevo...\n";
                    
                    $postResponse = $client->post('/api/3/fieldValues', [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => $testValue
                        ]
                    ]);
                    
                    if ($postResponse->successful()) {
                        echo "    ✅ POST exitoso (nuevo fieldValue)\n";
                    } else {
                        echo "    ❌ POST falló: " . $postResponse->status() . "\n";
                        echo "    Respuesta: " . $postResponse->body() . "\n";
                    }
                }
            }
        }
        
        sleep(1); // Esperar entre intentos
    }
    
    // ============================================
    // 9. ENLACE DIRECTO
    // ============================================
    $baseUrl = config('activecampaign.api.base_url');
    if ($baseUrl) {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $account = explode('.', $domain)[0] ?? 'tu-cuenta';
        echo "\n🔗 Enlace directo al contacto:\n";
        echo "  https://{$account}.activehosted.com/app/contacts/{$contactId}\n";
        
        echo "\n📋 Datos enviados para verificación manual:\n";
        echo "  Email: {$contactData['email']}\n";
        echo "  Apellidos en campo estándar: {$contactData['last_name']}\n";
        echo "  Apellido paterno (campo 4): {$testData['paternal_lastname']}\n";
        echo "  Apellido materno (campo 5): {$testData['maternal_lastname']}\n";
        echo "  Sexo (dropdown, campo 2): " . ($testData['gender'] == 1 ? 'Masculino' : 'Femenino') . "\n";
        echo "  Género (texto, campo 9): " . ($testData['gender'] == 1 ? 'Masculino' : 'Femenino') . "\n";
    }
    
} else {
    echo "❌ Error en sincronización:\n";
    echo "  {$result['error']}\n";
}

echo "\n🏁 Prueba completada.\n";
echo "\n💡 RECOMENDACIONES:\n";
echo "1. Elimina los campos DUPLICADOS en ActiveCampaign (tener 2 campos para género y 3 para apellidos es confuso)\n";
echo "2. Usa SOLO los campos personalizados para información que NO está en los campos estándar\n";
echo "3. Los apellidos ya van en el campo estándar 'Last Name' - no necesitas campos separados\n";
echo "4. Elige entre 'Sexo' (dropdown) O 'Género' (texto) - no ambos\n";



procedi a eliminar el campo genero y solo dejar sexo

elimine los campos de apellido materno y paterno y solo deje el campo de apellido completo