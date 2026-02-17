<?php
// test-200-save-token.php
$tokenGenerado = "akVUWno5b2pQdllpTlBQUXZtV2hOVTUvVVhXajFhRCtvakd1dGVWWWxKQT0=";
$cliente = "TestFAMEDIC";

// Guardar token en archivo para uso posterior
$tokenData = [
    'token' => $tokenGenerado,
    'cliente' => $cliente,
    'fecha_generacion' => date('Y-m-d H:i:s'),
    'vigencia' => '1 año',
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'headers' => [
        'X-API-USER' => 'Efevoo Pay',
        'X-API-KEY' => 'Hq#J0hs)jK+YqF6J'
    ]
];

// Guardar en archivo JSON
file_put_contents('efevoo_token.json', json_encode($tokenData, JSON_PRETTY_PRINT));

echo "✓ Token guardado exitosamente en 'efevoo_token.json'\n";
echo "Token: " . substr($tokenGenerado, 0, 20) . "...\n";
echo "Cliente: $cliente\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";