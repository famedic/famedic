<?php
// test-auth-completo.php

$config = [
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay',
    'api_key' => 'Hq#J0hs)jK+YqF6J',
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
    'clave' => '6nugHedWzw27MNB8',
    'cliente' => 'TestFAMEDIC',
    'vector' => 'MszjlcnTjGLNpNy3'
];

echo "========================================\n";
echo "PRUEBA COMPLETA DE AUTENTICACIÓN EFEVOOPAY\n";
echo "========================================\n\n";

// 1. Generar TOTP
function generateTOTP($secret) {
    $timestamp = floor(time() / 30);
    
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32Lookup = array_flip(str_split($base32Chars));
    
    $buffer = 0;
    $bitsLeft = 0;
    $key = '';
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $ch = $secret[$i];
        if (!isset($base32Lookup[$ch])) continue;
        
        $buffer = ($buffer << 5) | $base32Lookup[$ch];
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $key .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    
    $timeBytes = pack('N*', 0) . pack('N*', $timestamp);
    $hash = hash_hmac('sha1', $timeBytes, $key, true);
    
    $offset = ord($hash[19]) & 0xF;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

// 2. Función para hacer requests
function makeRequest($url, $headers, $body, $testName = '') {
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "TEST: $testName\n";
    echo str_repeat('=', 50) . "\n";
    
    echo "📤 ENVIANDO A: $url\n";
    echo "📋 HEADERS:\n";
    foreach ($headers as $h) {
        echo "  $h\n";
    }
    echo "📦 BODY (JSON):\n";
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_VERBOSE => true,
        CURLINFO_HEADER_OUT => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    
    echo "\n📥 RESPUESTA:\n";
    echo "HTTP Code: $httpCode\n";
    
    if ($error) {
        echo "❌ CURL Error: $error\n";
    }
    
    if ($response) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "✅ JSON válido recibido:\n";
            echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "📄 Respuesta RAW:\n";
            echo substr($response, 0, 500) . "\n";
        }
    } else {
        echo "⚠ No se recibió respuesta\n";
    }
    
    curl_close($ch);
    
    return [
        'code' => $httpCode, 
        'body' => $response,
        'decoded' => $decoded ?? null,
        'error' => $error
    ];
}

// ============================================
// PRUEBAS SECUENCIALES
// ============================================

$totp = generateTOTP($config['totp_secret']);
echo "🔑 TOTP Generado: $totp\n";

$hash = base64_encode(hash_hmac('sha256', $config['clave'], $totp, true));
echo "🔒 Hash (base64): $hash\n\n";

// TEST 1: Formato según documentación
echo "🧪 PRUEBA 1: Formato documentación\n";
$headers1 = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

$body1 = [
    'payload' => [
        'hash' => $hash,
        'cliente' => $config['cliente']
    ],
    'method' => 'getClientToken'
];

$result1 = makeRequest($config['api_url'], $headers1, $body1, 'Formato Documentación');

// TEST 2: Formato alternativo (sin 'payload' wrapper)
if ($result1['code'] != 200) {
    echo "\n\n🧪 PRUEBA 2: Formato alternativo\n";
    
    $body2 = [
        'method' => 'getClientToken',
        'hash' => $hash,
        'cliente' => $config['cliente']
    ];
    
    $result2 = makeRequest($config['api_url'], $headers1, $body2, 'Formato Directo');
}

// TEST 3: Headers diferentes (case sensitive)
if (($result1['code'] != 200 && (!isset($result2) || $result2['code'] != 200))) {
    echo "\n\n🧪 PRUEBA 3: Headers lowercase\n";
    
    $headers3 = [
        'content-type: application/json',
        'x-api-user: ' . $config['api_user'],
        'x-api-key: ' . $config['api_key']
    ];
    
    $body3 = [
        'payload' => [
            'hash' => $hash,
            'cliente' => $config['cliente']
        ],
        'method' => 'getClientToken'
    ];
    
    $result3 = makeRequest($config['api_url'], $headers3, $body3, 'Headers Lowercase');
}

// TEST 4: Hash sin base64
if (($result1['code'] != 200 && (!isset($result2) || $result2['code'] != 200) && (!isset($result3) || $result3['code'] != 200))) {
    echo "\n\n🧪 PRUEBA 4: Hash sin base64\n";
    
    $hashRaw = hash_hmac('sha256', $config['clave'], $totp);
    
    $body4 = [
        'payload' => [
            'hash' => $hashRaw,
            'cliente' => $config['cliente']
        ],
        'method' => 'getClientToken'
    ];
    
    $result4 = makeRequest($config['api_url'], $headers1, $body4, 'Hash sin Base64');
}

// TEST 5: Hash con orden invertido
if (($result1['code'] != 200 && (!isset($result2) || $result2['code'] != 200) && 
     (!isset($result3) || $result3['code'] != 200) && (!isset($result4) || $result4['code'] != 200))) {
    echo "\n\n🧪 PRUEBA 5: Hash orden invertido\n";
    
    $hashInvertido = base64_encode(hash_hmac('sha256', $totp, $config['clave'], true));
    
    $body5 = [
        'payload' => [
            'hash' => $hashInvertido,
            'cliente' => $config['cliente']
        ],
        'method' => 'getClientToken'
    ];
    
    $result5 = makeRequest($config['api_url'], $headers1, $body5, 'Hash Orden Invertido');
}

// ============================================
// RESUMEN
// ============================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESUMEN DE PRUEBAS\n";
echo str_repeat('=', 60) . "\n";

$tests = [
    '1. Formato Documentación' => $result1['code'] ?? 'No ejecutado',
    '2. Formato Directo' => $result2['code'] ?? 'No ejecutado',
    '3. Headers Lowercase' => $result3['code'] ?? 'No ejecutado',
    '4. Hash sin Base64' => $result4['code'] ?? 'No ejecutado',
    '5. Hash Invertido' => $result5['code'] ?? 'No ejecutado'
];

foreach ($tests as $test => $code) {
    echo sprintf("%-25s: %s\n", $test, $code);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RECOMENDACIONES:\n";
echo str_repeat('=', 60) . "\n";

echo "1. Si recibes 403: Credenciales incorrectas\n";
echo "2. Si recibes 400: Formato JSON incorrecto\n";
echo "3. Si recibes 200: ¡Éxito! Busca el 'token' en la respuesta\n";
echo "4. Contacta a EfevooPay si:\n";
echo "   - Todos devuelven 403 (API_USER/API_KEY incorrectos)\n";
echo "   - Necesitas confirmar el formato exacto del hash\n\n";

echo "📋 DATOS USADOS:\n";
echo "   API User: {$config['api_user']}\n";
echo "   API Key: {$config['api_key']}\n";
echo "   Cliente: {$config['cliente']}\n";
echo "   TOTP Secret: {$config['totp_secret']}\n";
echo "   Clave: {$config['clave']}\n";
echo "   Vector: {$config['vector']}\n";