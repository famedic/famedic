<?php
// test-fixed-token-simple.php

$fixed_token = 'Q2VzcEwzZEtHRnN6VnpGTXdNdWFCVHYwa0VsN2RSSEN5YlZJMEpUVU5DVT0=';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'method' => 'validateToken',
        'token' => $fixed_token
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-USER: Efevoo Pay',
        'X-API-KEY: Hq#J0hs)jK+YqF6J'
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "🔍 Validando token fijo...\n";
echo "HTTP Code: $httpCode\n";

if ($response) {
    $data = json_decode($response, true);
    echo "Respuesta: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
    
    if (($data['codigo'] ?? '') == '00') {
        echo "✅ Token válido\n";
        
        // También prueba obtener info del cliente
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => 'https://test-intgapi.efevoopay.com/v1/apiservice',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'method' => 'getClientInfo',
                'token' => $fixed_token
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-USER: Efevoo Pay',
                'X-API-KEY: Hq#J0hs)jK+YqF6J'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response2 = curl_exec($ch2);
        curl_close($ch2);
        
        echo "\n📋 Información del cliente:\n";
        $info = json_decode($response2, true);
        print_r($info);
    }
} else {
    echo "❌ No se recibió respuesta\n";
}