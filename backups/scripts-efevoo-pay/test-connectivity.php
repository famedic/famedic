<?php
// test-connectivity.php

echo "🔍 VERIFICANDO CONECTIVIDAD CON EFEVOOPAY\n";
echo "========================================\n\n";

// Probar conexión simple
$url = 'https://test-intgapi.efevoopay.com/v1/apiservice';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY => true, // Solo headers
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

echo "1. CONEXIÓN AL SERVIDOR:\n";
echo "   URL: $url\n";
echo "   HTTP Code: $httpCode\n";
echo "   Error: " . ($error ?: 'Ninguno') . "\n\n";

// Probar con diferentes combinaciones de credenciales
$testCredentials = [
    ['user' => 'Efevoo Pay', 'key' => 'Hq#J0hs)jK+YqF6J'],
    ['user' => 'efevoo pay', 'key' => 'Hq#J0hs)jK+YqF6J'], // lowercase
    ['user' => 'EFEVOO PAY', 'key' => 'Hq#J0hs)jK+YqF6J'], // uppercase
    ['user' => 'EfevooPay', 'key' => 'Hq#J0hs)jK+YqF6J'], // sin espacio
];

foreach ($testCredentials as $i => $cred) {
    echo "2. PRUEBA CREDENCIALES #" . ($i + 1) . ":\n";
    echo "   User: '{$cred['user']}'\n";
    echo "   Key: " . substr($cred['key'], 0, 5) . "...\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['method' => 'ping']),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-USER: ' . $cred['user'],
            'X-API-KEY: ' . $cred['key']
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   HTTP Code: $httpCode\n";
    if ($response) {
        $data = json_decode($response, true);
        echo "   Respuesta: " . json_encode($data) . "\n";
    }
    echo "\n";
}