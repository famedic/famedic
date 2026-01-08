<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Prueba EfevooPay Create Order ===\n\n";

$service = $app->make(\App\Services\EfevooPayService::class);

$orderData = [
    'description' => 'Prueba de integración EfevooPay',
    'items' => [
        [
            'name' => 'Prueba de Laboratorio $1 MXN',
            'quantity' => 1,
            'price' => 0.80,
        ]
    ],
    'subtotal' => 0.80,
    'total' => 0.80,
    'discount' => 0,
    'order_details' => [
        'customer_id' => 1,
        'test' => true,
        'integration_test' => 'Laravel 11 - ' . date('Y-m-d H:i:s'),
    ],
];

echo "📦 Datos de la orden:\n";
print_r($orderData);

echo "\n🚀 Enviando a EfevooPay...\n";

try {
    $result = $service->createOrder($orderData);
    
    echo "\n✅ ¡ÉXITO! Orden creada\n";
    echo "🪙 Token: " . $result['token'] . "\n";
    echo "🔗 Checkout URL: " . $result['checkout_url'] . "\n";
    echo "🔧 Mode: " . ($result['mode'] ?? 'N/A') . "\n";
    
    echo "\n📋 Puedes abrir esta URL en el navegador:\n";
    echo $result['checkout_url'] . "\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "📋 Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== Fin de prueba ===\n";
EOF