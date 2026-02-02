<?php
// test-tokenizacion-rapido.php

// ============================================
// CONFIGURACIÓN
// ============================================
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
// FUNCIONES MEJORADAS
// ============================================

function generateTOTP($secret) {
    $timestamp = floor(time() / 30);
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
    
    $secretKey = $result;
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

function generateHash($totp, $clave) {
    return base64_encode(hash_hmac('sha256', $clave, $totp, true));
}

function makeRequest($url, $headers, $body, $timeout = 15) {
    echo "   Timeout: {$timeout}s\n";
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => fopen('curl_last.log', 'w')
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $end = microtime(true);
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    
    curl_close($ch);
    
    echo "   Tiempo: " . round(($end - $start), 2) . "s\n";
    if ($error) {
        echo "   Error CURL: {$error}\n";
    }
    
    return [
        'code' => $httpCode, 
        'body' => $response,
        'error' => $error,
        'time' => round(($end - $start), 2)
    ];
}

// ============================================
// PRUEBA RÁPIDA
// ============================================

echo "========================================\n";
echo "PRUEBA RÁPIDA - TOKENIZACIÓN\n";
echo "========================================\n\n";

// 1. Verificar TOTP actual
echo "1. GENERANDO CREDENCIALES:\n";
$totp = generateTOTP($config['totp_secret']);
echo "   TOTP: {$totp}\n";

$hash = generateHash($totp, $config['clave']);
echo "   Hash: {$hash}\n";

// 2. Intentar obtener token con formato CORRECTO
echo "\n2. OBTENIENDO TOKEN DE CLIENTE:\n";

$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

// **IMPORTANTE: Usar el formato que SÍ funcionó**
$bodyTokenCliente = json_encode([
    'method' => 'getClientToken',
    'hash' => $hash,
    'cliente' => $config['cliente']
]);

echo "   Enviando...\n";
$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente, 10);

if ($result['code'] == 0) {
    echo "❌ TIMEOUT - Reintentando con timeout más corto...\n";
    $result = makeRequest($config['api_url'], $headers, $bodyTokenCliente, 5);
}

if ($result['code'] != 200) {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    if ($result['body']) {
        $resp = json_decode($result['body'], true);
        echo "   Mensaje: " . ($resp['msg'] ?? 'Sin mensaje') . "\n";
        echo "   Código: " . ($resp['codigo'] ?? 'N/A') . "\n";
    }
    
    // Intentar con el hash alternativo (orden invertido)
    echo "\n   Probando hash alternativo...\n";
    $hashAlternativo = base64_encode(hash_hmac('sha256', $totp, $config['clave'], true));
    $bodyAlternativo = json_encode([
        'method' => 'getClientToken',
        'hash' => $hashAlternativo,
        'cliente' => $config['cliente']
    ]);
    
    $result2 = makeRequest($config['api_url'], $headers, $bodyAlternativo, 5);
    
    if ($result2['code'] == 200) {
        $response = json_decode($result2['body'], true);
        if ($response['codigo'] == '00') {
            echo "✅ ÉXITO con hash alternativo!\n";
            $tokenCliente = $response['token'] ?? null;
        }
    }
    
    if (!isset($tokenCliente)) {
        exit;
    }
} else {
    $response = json_decode($result['body'], true);
    echo "✅ HTTP 200 recibido\n";
    echo "   Código: " . ($response['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($response['msg'] ?? 'Sin mensaje') . "\n";
    
    if (($response['codigo'] ?? '') == '00') {
        $tokenCliente = $response['token'] ?? null;
        echo "✅ Token obtenido: " . substr($tokenCliente, 0, 20) . "...\n";
    } else {
        echo "❌ No se pudo obtener token\n";
        exit;
    }
}

// 3. Si tenemos token, probar tokenización
if (isset($tokenCliente)) {
    echo "\n3. PROBANDO TOKENIZACIÓN CON MONTO MÍNIMO:\n";
    
    $tarjeta = '5267772159330969';
    $expiracion = '3111';
    $montoMinimo = '0.01'; // Mínimo absoluto
    
    echo "   Monto: \${$montoMinimo} MXN (mínimo absoluto)\n";
    echo "   ¿Continuar? (s/n): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) != 's') {
        echo "   Cancelado\n";
        exit;
    }
    
    // Preparar datos para tokenización
    $datos = [
        'track2' => $tarjeta . '=' . $expiracion,
        'amount' => $montoMinimo
    ];
    
    // Encriptar (VERIFICAR QUE ESTÉ BIEN)
    $plaintext = json_encode($datos, JSON_UNESCAPED_UNICODE);
    $encrypted = base64_encode(openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $config['clave'],
        OPENSSL_RAW_DATA,
        $config['vector']
    ));
    
    echo "   Datos encriptados: " . substr($encrypted, 0, 50) . "...\n";
    
    // Enviar tokenización
    $bodyTokenizacion = json_encode([
        'method' => 'getTokenize',
        'token' => $tokenCliente,
        'encrypt' => $encrypted
    ]);
    
    echo "   Enviando tokenización...\n";
    $resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion, 10);
    
    if ($resultToken['code'] == 200) {
        $responseToken = json_decode($resultToken['body'], true);
        echo "\n📊 RESULTADO:\n";
        echo "   Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($responseToken['msg'] ?? 'Sin mensaje') . "\n";
        
        if (($responseToken['codigo'] ?? '') == '00') {
            echo "\n🎉 ¡TOKENIZACIÓN EXITOSA!\n";
            echo "   Token tarjeta: " . ($responseToken['token'] ?? 'N/A') . "\n";
            
            if (isset($responseToken['token'])) {
                file_put_contents('token_tarjeta.txt', $responseToken['token']);
                echo "   Token guardado en 'token_tarjeta.txt'\n";
            }
        }
    } else {
        echo "❌ Error en tokenización: HTTP {$resultToken['code']}\n";
    }
}