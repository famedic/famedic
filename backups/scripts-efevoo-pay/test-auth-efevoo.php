<?php
// test-auth-efevoo.php

$config = [
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay',
    'api_key' => 'Hq#J0hs)jK+YqF6J',
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
    'clave' => '6nugHedWzw27MNB8',
    'cliente' => 'TestFAMEDIC',
    'vector' => 'MszjlcnTjGLNpNy3'
];

// ============================================
// FUNCIÓN TOTP CORREGIDA (usa librería PHP)
// ============================================
function generateTOTP($secret) {
    // Usar base32_decode nativo si existe
    if (function_exists('base32_decode')) {
        $key = base32_decode($secret);
    } else {
        // Implementación manual más robusta
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32Lookup = array_flip(str_split($base32Chars));
        
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        
        for ($i = 0; $i < strlen($secret); $i++) {
            $ch = $secret[$i];
            if (!isset($base32Lookup[$ch])) continue;
            
            $buffer = ($buffer << 5) | $base32Lookup[$ch];
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        $key = $result;
    }
    
    $timestamp = floor(time() / 30);
    $timestampBytes = pack('N*', 0) . pack('N*', $timestamp);
    
    $hash = hash_hmac('sha1', $timestampBytes, $key, true);
    $offset = ord($hash[19]) & 0xF;
    
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

// ============================================
// FUNCIÓN DE CONEXIÓN MEJORADA
// ============================================
function makeRequest($url, $headers, $body) {
    echo "========================================\n";
    echo "ENVIANDO SOLICITUD:\n";
    echo "URL: $url\n";
    echo "Headers:\n";
    foreach ($headers as $h) {
        echo "  $h\n";
    }
    echo "Body: " . substr(json_encode($body), 0, 200) . "\n";
    echo "========================================\n\n";
    
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
        CURLOPT_STDERR => fopen('curl_verbose.log', 'w+'),
        CURLINFO_HEADER_OUT => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    echo "RESPUESTA:\n";
    echo "HTTP Code: $httpCode\n";
    echo "Error: " . ($error ?: 'Ninguno') . "\n";
    
    if ($response) {
        echo "Body:\n";
        print_r(json_decode($response, true));
    }
    
    curl_close($ch);
    
    return ['code' => $httpCode, 'body' => $response];
}

// ============================================
// PRUEBA 1: OBTENER TOKEN CON HEADERS CORRECTOS
// ============================================
echo "🔍 PRUEBA 1: OBTENER TOKEN DE CLIENTE\n";
echo "========================================\n\n";

// Generar TOTP
$totp = generateTOTP($config['totp_secret']);
echo "TOTP Generado: $totp\n";

// Generar hash (VERIFICA ESTA PARTE)
$hash = base64_encode(hash_hmac('sha256', $config['clave'], $totp, true));
echo "Hash Generado: $hash\n\n";

// Headers CORRECTOS (como en la documentación)
$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

// Body CORRECTO según documentación
$body = [
    'payload' => [
        'hash' => $hash,
        'cliente' => $config['cliente']
    ],
    'method' => 'getClientToken'
];

echo "Enviando solicitud...\n";
$result = makeRequest($config['api_url'], $headers, $body);

// ============================================
// PRUEBA 2: SI FALLA, PROBAR CON OTRO FORMATO
// ============================================
if ($result['code'] != 200) {
    echo "\n\n🔍 PRUEBA 2: FORMATO ALTERNATIVO\n";
    echo "========================================\n\n";
    
    // Formato alternativo que he visto en otras APIs
    $body2 = [
        'method' => 'getClientToken',
        'hash' => $hash,
        'cliente' => $config['cliente']
    ];
    
    echo "Probando formato alternativo...\n";
    $result2 = makeRequest($config['api_url'], $headers, $body2);
    
    // ============================================
    // PRUEBA 3: SIN PAYLOAD WRAPPER
    // ============================================
    if ($result2['code'] != 200) {
        echo "\n\n🔍 PRUEBA 3: FORMATO DIRECTO\n";
        echo "========================================\n\n";
        
        $body3 = [
            'method' => 'getClientToken',
            'payload' => json_encode([
                'hash' => $hash,
                'cliente' => $config['cliente']
            ])
        ];
        
        echo "Probando formato directo...\n";
        $result3 = makeRequest($config['api_url'], $headers, $body3);
    }
}

// ============================================
// VERIFICAR ARCHIVO DE LOG
// ============================================
echo "\n\n📄 CONTENIDO DEL LOG CURL:\n";
echo "========================================\n";
if (file_exists('curl_verbose.log')) {
    echo file_get_contents('curl_verbose.log');
} else {
    echo "No se generó log\n";
}