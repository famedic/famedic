<?php
// test-3ds-step-by-step.php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Test paso a paso de 3DS EfevooPay\n";
echo "=====================================\n";

use App\Models\Customer;
use App\Services\EfevooPayService;

$service = app(EfevooPayService::class);

// Paso 1: Health check
echo "\n1. Health Check:\n";
$health = $service->healthCheck();
echo "   Status: " . $health['status'] . "\n";
echo "   Message: " . $health['message'] . "\n";

// Paso 2: Obtener token
echo "\n2. Obtener Token:\n";
$tokenResult = $service->getClientToken(false, 'tokenize');
echo "   Success: " . ($tokenResult['success'] ? '✅' : '❌') . "\n";
echo "   Type: " . ($tokenResult['type'] ?? 'N/A') . "\n";

if ($tokenResult['success']) {
    // Paso 3: Probar tokenización normal
    echo "\n3. Probar Tokenización Normal:\n";
    
    $testCard = [
        'card_number' => '4111111111111111',
        'expiration' => '1228',
        'cvv' => '123',
        'card_holder' => 'TEST USER',
        'amount' => 1.50,
        'alias' => 'Test Card',
    ];
    
    $customer = Customer::first();
    
    if ($customer) {
        try {
            echo "   Customer ID: " . $customer->id . "\n";
            
            // Probar tokenización normal (sin 3DS)
            $result = $service->fastTokenize($testCard, $customer->id);
            
            echo "   Result: " . ($result['success'] ? '✅ Éxito' : '❌ Error') . "\n";
            echo "   Message: " . ($result['message'] ?? 'N/A') . "\n";
            
            if ($result['success']) {
                echo "   Token ID: " . ($result['token_id'] ?? 'N/A') . "\n";
            }
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ⚠️ No hay customers en la base de datos\n";
    }
}

// Paso 4: Verificar si 3DS está disponible
echo "\n4. Verificar 3DS:\n";
echo "   Método 'payments3DS_GetLink' probablemente no está habilitado\n";
echo "   Contacta a EfevooPay para habilitarlo\n";

// Paso 5: Recomendaciones
echo "\n5. Recomendaciones:\n";
echo "   - Contactar a EfevooPay y preguntar:\n";
echo "     * '¿Está habilitado el método payments3DS_GetLink?'\n";
echo "     * '¿Cuál es el fiid_comercio para mi cuenta?'\n";
echo "     * '¿Hay documentación específica para 3DS?'\n";
echo "   - Mientras tanto, usar tokenización normal (getTokenize)\n";
echo "   - La tokenización normal ya funciona según tus logs\n";

echo "\n=====================================\n";
echo "Test completado\n";