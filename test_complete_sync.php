<?php
// test_complete_sync.php
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🧪 PRUEBA COMPLETA DE SINCRONIZACIÓN\n\n";

// 1. Crear usuario con TODOS los campos
$timestamp = time();
$email = "complete_test_{$timestamp}@famedic.com";

$user = new User();
$user->name = 'Completo';
$user->paternal_lastname = 'ApellidoPaterno';
$user->maternal_lastname = 'ApellidoMaterno';
$user->email = $email;
$user->phone = '+525511223344';
$user->phone_country = 'MX';
$user->birth_date = Carbon::create(1988, 3, 25);
$user->gender = 2; // Femenino
$user->state = 'JA'; // Jalisco
$user->password = Hash::make('password123');
$user->save();

echo "✅ Usuario creado:\n";
echo "  ID: {$user->id}\n";
echo "  Email: {$user->email}\n";
echo "  Nombre: {$user->name}\n";
echo "  Apellido paterno: {$user->paternal_lastname}\n";
echo "  Apellido materno: {$user->maternal_lastname}\n";
echo "  Teléfono: {$user->phone}\n";
echo "  País teléfono: {$user->phone_country}\n";
echo "  Fecha nacimiento: " . $user->birth_date->format('Y-m-d') . "\n";
echo "  Género: " . ($user->gender == 1 ? 'Masculino' : 'Femenino') . "\n";
echo "  Estado: {$user->state}\n\n";

// 2. Preparar datos
$service = app(App\Services\ActiveCampaignService::class);

echo "📝 Preparando datos...\n";
$userData = $service->prepareUserData($user);

echo "Datos básicos para ActiveCampaign:\n";
echo "  First Name: {$userData['first_name']}\n";
echo "  Last Name: {$userData['last_name']}\n";
echo "  Email: {$userData['email']}\n";
echo "  Phone: " . ($userData['phone'] ?? 'N/A') . "\n";

echo "\nCampos personalizados a enviar:\n";
foreach ($userData['custom_fields'] ?? [] as $id => $val) {
    $fieldName = match($id) {
        3 => 'Fecha Nacimiento',
        4 => 'Apellido Paterno',
        5 => 'Apellido Materno',
        6 => 'Fecha Registro',
        8 => 'País Teléfono',
        9 => 'Género',
        10 => 'Entidad Federativa',
        default => "Campo {$id}"
    };
    echo "  {$fieldName} (ID {$id}): '{$val}'\n";
}

// 3. Ejecutar sincronización completa
echo "\n🚀 Ejecutando sincronización completa...\n";

$contactData = [
    'email' => $userData['email'],
    'first_name' => $userData['first_name'],
    'last_name' => $userData['last_name'],
    'phone' => $userData['phone'] ?? null,
];

$listId = config('activecampaign.lists.default', 5);
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'TestCompleto'];

$result = $service->syncContactWithCustomFields(
    $contactData, 
    $listId, 
    $tags, 
    $userData['custom_fields'] ?? []
);

if ($result['success']) {
    echo "✅ Sincronización exitosa!\n";
    echo "  Contact ID: {$result['contact_id']}\n";
    echo "  Acción: {$result['action']}\n";
    
    // 4. Verificar TODOS los campos
    echo "\n🔍 Verificando TODOS los campos...\n";
    sleep(3);
    
    $expectedFields = [
        3 => 'Fecha Nacimiento',
        4 => 'Apellido Paterno',
        5 => 'Apellido Materno',
        6 => 'Fecha Registro',
        8 => 'País Teléfono',
        9 => 'Género',
        10 => 'Entidad Federativa',
    ];
    
    foreach ($expectedFields as $fieldId => $fieldName) {
        $expectedValue = $userData['custom_fields'][$fieldId] ?? null;
        
        if ($expectedValue) {
            $actual = $service->getFieldValue($result['contact_id'], $fieldId);
            $actualValue = $actual['value'] ?? 'null';
            
            if ($actualValue === $expectedValue) {
                echo "  ✅ {$fieldName}: Correcto ('{$actualValue}')\n";
            } else {
                echo "  ❌ {$fieldName}: FALLO\n";
                echo "     Esperado: '{$expectedValue}'\n";
                echo "     Obtenido: '{$actualValue}'\n";
            }
        } else {
            echo "  ⚠️ {$fieldName}: No configurado en custom_fields\n";
        }
        
        usleep(200000); // 0.2 segundos
    }
    
    // 5. Mostrar enlace
    $baseUrl = config('activecampaign.api.base_url');
    if ($baseUrl) {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $account = explode('.', $domain)[0] ?? 'tu-cuenta';
        echo "\n🔗 Enlace al contacto:\n";
        echo "https://{$account}.activehosted.com/app/contacts/{$result['contact_id']}\n";
    }
    
} else {
    echo "❌ Error: {$result['error']}\n";
}

// 6. Limpieza
echo "\n¿Eliminar usuario de prueba? (s/n): ";
$handle = fopen("php://stdin", "r");
$answer = trim(fgets($handle));

if (strtolower($answer) === 's') {
    $user->delete();
    echo "✅ Usuario eliminado\n";
} else {
    echo "⚠️ Usuario mantenido (ID: {$user->id})\n";
}

fclose($handle);
echo "\n🏁 Prueba completada\n";