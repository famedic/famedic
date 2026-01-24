<?php

use App\Models\User;
use App\Services\ActiveCampaignService;

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Test de ActiveCampaign ===\n";

// 1. Verificar configuración
echo "\n1. Configuración:\n";
echo "Base URL: " . config('activecampaign.api.base_url') . "\n";
echo "Sync enabled: " . (config('activecampaign.sync.enabled') ? 'Yes' : 'No') . "\n";
echo "List ID: " . config('activecampaign.lists.default', 5) . "\n";

// 2. Buscar usuario
$email = 'llozano@odessa.com.mx'; // Cambia por un email real de tu BD
$user = User::where('email', $email)->first();

if (!$user) {
    echo "\n❌ Usuario no encontrado. Buscando último usuario...\n";
    $user = User::latest()->first();
    
    if (!$user) {
        echo "❌ No hay usuarios en la BD\n";
        exit(1);
    }
    
    echo "✅ Usando último usuario: {$user->email}\n";
}

// 3. Mostrar datos del usuario
echo "\n2. Datos del usuario:\n";
echo "ID: {$user->id}\n";
echo "Email: {$user->email}\n";
echo "Nombre: {$user->name}\n";
echo "Apellido Paterno: {$user->paternal_lastname}\n";
echo "Apellido Materno: {$user->maternal_lastname}\n";
echo "Teléfono: {$user->phone}\n";
echo "Género: {$user->gender?->value}\n";
echo "Estado: {$user->state}\n";

// 4. Testear servicio
echo "\n3. Probando servicio ActiveCampaign...\n";
try {
    $service = app(ActiveCampaignService::class);
    
    // Test simple de API
    echo "Realizando test de conexión...\n";
    
    // Primero, verificar que podemos obtener tags
    $tagName = config('activecampaign.tags.registro_nuevo', 'RegistroNuevo');
    echo "Buscando/creando tag: {$tagName}\n";
    
    $tagId = $service->getOrCreateTag($tagName);
    
    if ($tagId) {
        echo "✅ Tag ID: {$tagId}\n";
    } else {
        echo "❌ No se pudo obtener/crear tag\n";
    }
    
    echo "\n✅ Servicio funciona correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error en servicio: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
}