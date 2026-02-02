<?php
// test-300-verify-token.php
require 'vendor/autoload.php';

// Cargar token guardado
$tokenData = json_decode(file_get_contents('efevoo_token.json'), true);
$token = $tokenData['token'];
$cliente = $tokenData['cliente'];

// Generar nuevo TOTP y hash
$credenciales = [
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
    'clave' => '6nugHedWzw27MNB8'
];

function generateTOTP($secret) {
    $timestamp = floor(time() / 30);
    $secretKey = base32_decode($secret);
    $timestampBytes = pack('N*', 0) . pack('N*', $timestamp);
    
    $hash = hash_hmac('sha1', $timestampBytes, $secretKey, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % pow(10, 6);
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function base32_decode($base32) {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32Lookup = array_flip(str_split($base32Chars));
    
    $buffer = 0;
    $bitsLeft = 0;
    $result = '';
    
    for ($i = 0; $i < strlen($base32); $i++) {
        $ch = $base32[$i];
        if (!isset($base32Lookup[$ch])) continue;
        
        $buffer = ($buffer << 5) | $base32Lookup[$ch];
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    
    return $result;
}

// Generar TOTP y hash para la nueva solicitud
$totp = generateTOTP($credenciales['totp_secret']);
$hash = base64_encode(hash_hmac('sha256', $credenciales['clave'], $totp, true));

// Prueba: Consultar información con el token
function testTokenOperations($token, $hash, $cliente) {
    $client = new GuzzleHttp\Client();
    $headers = [
        'Content-Type' => 'application/json',
        'X-API-USER' => 'Efevoo Pay',
        'X-API-KEY' => 'Hq#J0hs)jK+YqF6J',
        'Authorization' => 'Bearer ' . $token // Si la API requiere esto
    ];
    
    // Diferentes operaciones que podrías probar
    $operations = [
        [
            'method' => 'getBalance',
            'payload' => ['hash' => $hash, 'cliente' => $cliente]
        ],
        [
            'method' => 'getTransactions',
            'payload' => ['hash' => $hash, 'cliente' => $cliente, 'fecha_inicio' => date('Y-m-01'), 'fecha_fin' => date('Y-m-d')]
        ],
        [
            'method' => 'testConnection',
            'payload' => ['hash' => $hash, 'cliente' => $cliente]
        ]
    ];
    
    echo "=== Probando operaciones con el token ===\n";
    
    foreach ($operations as $op) {
        echo "\nOperación: " . $op['method'] . "\n";
        
        $body = json_encode([
            'payload' => $op['payload'],
            'method' => $op['method']
        ]);
        
        try {
            $response = $client->request('POST', 'https://test-intgapi.efevoopay.com/v1/apiservice', [
                'headers' => $headers,
                'body' => $body,
                'verify' => false,
                'timeout' => 30
            ]);
            
            echo "Status: " . $response->getStatusCode() . "\n";
            $responseBody = $response->getBody()->getContents();
            echo "Response: " . $responseBody . "\n";
            
            // Verificar si es un error común
            $data = json_decode($responseBody, true);
            if (isset($data['codigo']) && $data['codigo'] != '100') {
                echo "⚠ Código de error: " . $data['codigo'] . "\n";
            }
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
        
        sleep(1); // Esperar entre operaciones
    }
}

// Ejecutar pruebas
testTokenOperations($token, $hash, $cliente);