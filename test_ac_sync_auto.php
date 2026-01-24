<?php
// test_ac_sync_auto.php
// Ejecuta directamente: php test_ac_sync_auto.php

use App\Models\User;
use App\Enums\Gender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function logMessage($message, $type = 'info') {
    $prefix = match($type) {
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        default => '📝'
    };
    echo "{$prefix} {$message}\n";
}

echo "🧪 PRUEBA AUTOMÁTICA DE SINCRONIZACIÓN ACTIVECAMPAIGN\n\n";

// 1. Crear usuario de prueba
logMessage("1. Creando usuario de prueba...");
$timestamp = time();
$email = "test_auto_{$timestamp}@famedic.com";

try {
    $user = new User();
    $user->name = 'Auto';
    $user->paternal_lastname = 'Test';
    $user->maternal_lastname = 'Script';
    $user->email = $email;
    $user->phone = '+521111111111';
    $user->phone_country = 'MX';
    $user->birth_date = Carbon::create(1985, 6, 20);
    //$user->gender = Gender::Femenino;
    $user->state = 'DF'; // Ciudad de México
    $user->password = Hash::make('password123');
    
    $user->save();
    
    logMessage("Usuario creado: ID {$user->id}, Email: {$email}", 'success');
    
} catch (Exception $e) {
    logMessage("Error creando usuario: " . $e->getMessage(), 'error');
    exit(1);
}

// 2. Inicializar servicio
logMessage("\n2. Inicializando servicio ActiveCampaign...");
$service = app(App\Services\ActiveCampaignService::class);

if (!$service->testConnection()) {
    logMessage("Error de conexión a ActiveCampaign", 'error');
    $user->delete();
    exit(1);
}
logMessage("Conexión OK", 'success');

// 3. Preparar datos
logMessage("\n3. Preparando datos...");
$userData = $service->prepareUserData($user);
$customFields = $userData['custom_fields'] ?? [];

logMessage("Datos básicos preparados: {$userData['first_name']} {$userData['last_name']}");
if (!empty($customFields)) {
    logMessage(count($customFields) . " campos personalizados listos");
} else {
    logMessage("No hay campos personalizados", 'warning');
}

// 4. Verificar contacto existente
logMessage("\n4. Verificando contacto existente...");
$existingContact = $service->getContactByEmail($user->email);
if ($existingContact) {
    logMessage("Contacto ya existe (ID: {$existingContact['id']})", 'warning');
} else {
    logMessage("No existe contacto previo", 'success');
}

// 5. Ejecutar sincronización
logMessage("\n5. Ejecutando sincronización...");
$contactData = [
    'email' => $userData['email'],
    'first_name' => $userData['first_name'],
    'last_name' => $userData['last_name'],
    'phone' => $userData['phone'] ?? null,
];

$listId = config('activecampaign.lists.default', 5);
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'AutoTest'];

$result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);

if (!$result['success']) {
    logMessage("Error: {$result['error']}", 'error');
    $user->delete();
    exit(1);
}

logMessage("Sincronización exitosa! Contact ID: {$result['contact_id']}", 'success');

// 6. Verificar campos
logMessage("\n6. Verificando campos personalizados...");
sleep(3);

$verificationResults = [];
foreach ($customFields as $fieldId => $expectedValue) {
    $actual = $service->getFieldValue($result['contact_id'], $fieldId);
    $actualValue = $actual['value'] ?? 'null';
    
    $isCorrect = ($actualValue === $expectedValue);
    $verificationResults[] = [
        'field_id' => $fieldId,
        'expected' => $expectedValue,
        'actual' => $actualValue,
        'correct' => $isCorrect
    ];
    
    sleep(1);
}

// 7. Mostrar resultados
logMessage("\n7. RESULTADOS DE LA VERIFICACIÓN:");
echo str_repeat("-", 50) . "\n";

$correctCount = 0;
foreach ($verificationResults as $result) {
    $status = $result['correct'] ? '✅' : '❌';
    echo "{$status} Campo ID {$result['field_id']}:\n";
    echo "   Esperado: '{$result['expected']}'\n";
    echo "   Obtenido: '{$result['actual']}'\n\n";
    
    if ($result['correct']) $correctCount++;
}

echo str_repeat("-", 50) . "\n";
echo "Total: " . count($verificationResults) . " campos verificados\n";
echo "Correctos: {$correctCount}\n";
echo "Incorrectos: " . (count($verificationResults) - $correctCount) . "\n\n";

if ($correctCount === count($verificationResults)) {
    logMessage("🎉 ¡TODOS los campos se sincronizaron correctamente!", 'success');
} elseif ($correctCount > 0) {
    logMessage("⚠️ Algunos campos se sincronizaron correctamente", 'warning');
} else {
    logMessage("❌ Ningún campo se sincronizó correctamente", 'error');
}

// 8. Limpieza
logMessage("\n8. Limpiando usuario de prueba...");
$user->delete();
logMessage("Usuario eliminado de la base de datos", 'success');

logMessage("\n🏁 Prueba completada!");