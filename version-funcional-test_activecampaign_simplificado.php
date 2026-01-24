<?php
// test_activecampaign_simplificado.php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🎯 PRUEBA CONFIGURACIÓN SIMPLIFICADA - ACTIVECAMPAIGN\n\n";

// ============================================
// 1. DATOS DE PRUEBA
// ============================================
$testData = [
    'name' => 'Prueba',
    'paternal_lastname' => 'Simplificada',
    'maternal_lastname' => 'Script',
    'email' => 'prueba_simple_' . time() . '@famedic.com',
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
// 3. PREPARAR DATOS CON LA NUEVA CONFIGURACIÓN
// ============================================
echo "\n🛠️  Preparando datos con la nueva configuración...\n";

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

// 3.2 Preparar campos personalizados SEGÚN TU NUEVA CONFIGURACIÓN
$customFields = [];

// Lista de campos personalizados que ahora TIENES en ActiveCampaign:
// ID 2: "Sexo" (dropdown) - ESTE es el que ahora usas
// ID 3: "Fecha de Nacimiento"
// ID 6: "Fecha de registro"
// ID 8: "País Teléfono"
// ID 10: "Entidad Federativa"
// ID 11: "Referenciado Por" (si lo usas)

$allCustomFields = [
    2 => ['Sexo (dropdown)', $testData['gender'] == 1 ? 'Masculino' : 'Femenino'],
    3 => ['Fecha de Nacimiento', $testData['birth_date']],
    6 => ['Fecha de registro', $testData['created_at']],
    8 => ['País Teléfono', $testData['phone_country']],
    10 => ['Entidad Federativa', 'Nuevo León'], // Convertir NL a nombre completo
    // 11 => ['Referenciado Por', ''], // Descomenta si usas este campo
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
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'TestSimplificado'];

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
    echo "\n🔍 Verificando campos...\n";
    
    $contactId = $result['contact_id'];
    sleep(3); // Esperar a que la API procese
    
    // Usar reflexión para acceder al cliente HTTP
    $reflection = new ReflectionClass($service);
    $clientProperty = $reflection->getProperty('client');
    $clientProperty->setAccessible(true);
    $client = $clientProperty->getValue($service);
    
    // Verificar cada campo personalizado
    foreach ($customFields as $fieldId => $expectedValue) {
        echo "  Verificando campo ID {$fieldId}... ";
        
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
                echo "❌ FALLÓ (esperado: '{$expectedValue}', obtenido: '{$actualValue}')\n";
            }
        } else {
            echo "❌ Error en la búsqueda: " . $searchResponse->status() . "\n";
        }
        
        usleep(200000); // 0.2 segundos
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
                6 => 'Fecha Registro',
                8 => 'País Teléfono',
                10 => 'Entidad Federativa',
                11 => 'Referenciado Por',
            ];
            
            foreach ($fieldValues as $fv) {
                if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                    $fieldId = (int)$fv['field'];
                    $fieldName = $fieldNames[$fieldId] ?? "Campo {$fieldId}";
                    $value = $fv['value'];
                    echo "  - {$fieldName} (ID {$fieldId}): '{$value}'\n";
                }
            }
        } else {
            echo "  - No hay fieldValues\n";
        }
    }
    
    // ============================================
    // 7. ENLACE DIRECTO
    // ============================================
    $baseUrl = config('activecampaign.api.base_url');
    if ($baseUrl) {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $account = explode('.', $domain)[0] ?? 'tu-cuenta';
        echo "\n🔗 Enlace directo al contacto:\n";
        echo "  https://{$account}.activehosted.com/app/contacts/{$contactId}\n";
    }
    
} else {
    echo "❌ Error en sincronización:\n";
    echo "  {$result['error']}\n";
}

echo "\n🏁 Prueba completada.\n";