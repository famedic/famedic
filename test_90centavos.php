<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Prueba EfevooPay 0.90 MXN ===\n\n";

try {
    // 1. Crear servicio
    echo "1. Cargando servicio...\n";
    $service = $app->make(\App\Services\EfevooPayService::class);
    echo "   ✅ Servicio cargado\n";
    
    // 2. Datos de la orden
    echo "\n2. Preparando orden de 0.90 MXN...\n";
    $orderData = [
        'description' => 'Prueba EfevooPay - 0.90 MXN',
        'items' => [
            [
                'name' => 'Producto de Prueba',
                'quantity' => 1,
                'price' => 0.90,
            ]
        ],
        'subtotal' => 0.90,
        'total' => 0.90,
        'discount' => 0,
        'order_details' => [
            'test' => true,
            'amount' => '0.90 MXN',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];
    
    echo "   Descripción: " . $orderData['description'] . "\n";
    echo "   Producto: " . $orderData['items'][0]['name'] . "\n";
    echo "   Precio: " . $orderData['items'][0]['price'] . " MXN\n";
    echo "   Total: " . $orderData['total'] . " MXN\n";
    
    // 3. Crear orden
    echo "\n3. Creando orden en EfevooPay...\n";
    $result = $service->createOrder($orderData);
    
    echo "\n✅ ¡ÉXITO!\n";
    echo "   Token: " . $result['token'] . "\n";
    echo "   Checkout URL: " . $result['checkout_url'] . "\n";
    echo "   Mode: " . ($result['mode'] ?? 'N/A') . "\n";
    
    echo "\n🔗 URL para probar:\n";
    echo $result['checkout_url'] . "\n";
    
    echo "\n📝 Instrucciones:\n";
    echo "1. Copia la URL de arriba\n";
    echo "2. Ábrela en el navegador\n";
    echo "3. Completa los datos de pago\n";
    echo "4. Verifica que te redirija correctamente\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "📋 Trace: " . $e->getTraceAsString() . "\n";
    
    // Debug adicional
    echo "\n🔍 Debug adicional:\n";
    echo "¿Existe TOTPService? " . (class_exists(\App\Services\TOTPService::class) ? 'Sí' : 'No') . "\n";
    
    if (isset($service)) {
        echo "Métodos del servicio: " . implode(', ', get_class_methods($service)) . "\n";
    }
}

echo "\n=== Fin ===\n";
