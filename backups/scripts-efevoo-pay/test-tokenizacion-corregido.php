<?php
// test-tokenizacion-corregido.php

// ============================================
// CONFIGURACIÓN
// ============================================
$config = [
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay',
    'api_key' => 'Hq#J0hs)jK+YqF6J',
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
    'clave' => '6nugHedWzw27MNB8',      // 16 caracteres
    'cliente' => 'TestFAMEDIC',
    'vector' => 'MszjlcnTjGLNpNy3'      // 16 caracteres
];

// ============================================
// FUNCIONES CORREGIDAS SEGÚN DOCUMENTACIÓN
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

/**
 * ENCRIPTACIÓN CORREGIDA según documentación C#
 * 
 * C# hace: Encoding.UTF8.GetBytes(keyBase64)
 * Esto convierte el string UTF8 a bytes, NO aplica padding
 */
function encryptDataAES($data, $clave, $vector) {
    // JSON exactamente como en la documentación
    $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    echo "   JSON a encriptar: " . $plaintext . "\n";
    echo "   Longitud JSON: " . strlen($plaintext) . " bytes\n";
    
    // ¡CORRECCIÓN! Usar UTF8 directamente, NO padding
    $key = $clave;  // string de 16 caracteres
    $iv = $vector;  // string de 16 caracteres
    
    echo "   Clave (UTF8): '$key'\n";
    echo "   Longitud clave: " . strlen($key) . " caracteres\n";
    echo "   Bytes clave: " . bin2hex($key) . "\n";
    
    echo "   IV (UTF8): '$iv'\n";
    echo "   Longitud IV: " . strlen($iv) . " caracteres\n";
    echo "   Bytes IV: " . bin2hex($iv) . "\n";
    
    // Encriptar con AES-128-CBC, PKCS7 padding (igual que C#)
    $ciphertext = openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    if ($ciphertext === false) {
        echo "   ❌ Error en openssl_encrypt: " . openssl_error_string() . "\n";
        return false;
    }
    
    $encrypted = base64_encode($ciphertext);
    echo "   Texto encriptado (Base64): " . $encrypted . "\n";
    echo "   Longitud encriptado: " . strlen($encrypted) . " caracteres\n";
    
    return $encrypted;
}

function makeRequest($url, $headers, $body) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'body' => $response];
}

// ============================================
// PRUEBA CON DATOS EXACTOS DE DOCUMENTACIÓN
// ============================================

echo "========================================\n";
echo "TOKENIZACIÓN CORREGIDA (según doc C#)\n";
echo "========================================\n\n";

// 1. Obtener token de cliente
echo "1. OBTENIENDO TOKEN DE CLIENTE\n";
$totp = generateTOTP($config['totp_secret']);
$hash = generateHash($totp, $config['clave']);

$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

$bodyTokenCliente = json_encode([
    'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
    'method' => 'getClientToken'
]);

echo "   Enviando... ";
$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

if ($result['code'] == 200) {
    $response = json_decode($result['body'], true);
    if (isset($response['token'])) {
        $tokenCliente = $response['token'];
        echo "✅ ÉXITO\n";
        echo "   Token: " . substr($tokenCliente, 0, 30) . "...\n";
    } else {
        echo "❌ ERROR - No token en respuesta\n";
        exit;
    }
} else {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    exit;
}

echo "\n2. PRUEBA 1: DATOS EXACTOS DE DOCUMENTACIÓN C#\n";

// Usar los datos EXACTOS del ejemplo C#
$datosEjemplo = [
    'track2' => '1234567896587458=3005',  // Exacto del ejemplo
    'amount' => '1.00'                     // Exacto del ejemplo
];

echo "   Usando datos del ejemplo C#:\n";
echo "   - track2: " . $datosEjemplo['track2'] . "\n";
echo "   - amount: " . $datosEjemplo['amount'] . "\n";

$encrypted = encryptDataAES($datosEjemplo, $config['clave'], $config['vector']);

if ($encrypted) {
    $bodyTokenizacion = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'getTokenize'
    ]);
    
    echo "   Enviando solicitud... ";
    $resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion);
    
    if ($resultToken['code'] == 200) {
        $responseToken = json_decode($resultToken['body'], true);
        echo "✅ RESPUESTA\n";
        echo "   Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($responseToken['mensaje'] ?? $responseToken['msg'] ?? '') . "\n";
        
        if (isset($responseToken['token'])) {
            echo "   🎉 ¡TOKEN OBTENIDO!\n";
            echo "   Token tarjeta: " . $responseToken['token'] . "\n";
            
            file_put_contents('token_exito_doc.txt', json_encode([
                'datos_usados' => $datosEjemplo,
                'token_tarjeta' => $responseToken['token'],
                'fecha' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_UNICODE));
        }
    } else {
        echo "❌ ERROR HTTP: " . $resultToken['code'] . "\n";
        echo "   Respuesta: " . $resultToken['body'] . "\n";
    }
}

echo "\n3. PRUEBA 2: CON TU TARJETA REAL\n";

// Ahora probar con tu tarjeta real
$datosReales = [
    'track2' => '5267772159330969=1131',  // Tu tarjeta
    'amount' => '2.50'                     // Monto de prueba
];

echo "   Usando tu tarjeta real:\n";
echo "   - track2: " . substr($datosReales['track2'], 0, 6) . "****" . substr($datosReales['track2'], -4) . "\n";
echo "   - amount: " . $datosReales['amount'] . "\n";

$encrypted2 = encryptDataAES($datosReales, $config['clave'], $config['vector']);

if ($encrypted2) {
    $bodyTokenizacion2 = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted2
        ],
        'method' => 'getTokenize'
    ]);
    
    echo "   Enviando solicitud... ";
    $resultToken2 = makeRequest($config['api_url'], $headers, $bodyTokenizacion2);
    
    if ($resultToken2['code'] == 200) {
        $responseToken2 = json_decode($resultToken2['body'], true);
        echo "✅ RESPUESTA\n";
        echo "   Código: " . ($responseToken2['codigo'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($responseToken2['mensaje'] ?? $responseToken2['msg'] ?? '') . "\n";
        
        if (isset($responseToken2['token'])) {
            echo "   🎉 ¡TOKEN OBTENIDO!\n";
            echo "   Token tarjeta: " . $responseToken2['token'] . "\n";
            
            file_put_contents('token_tarjeta_real.txt', json_encode([
                'tarjeta' => substr($datosReales['track2'], 0, 6) . "****" . substr($datosReales['track2'], -9, 4),
                'token_tarjeta' => $responseToken2['token'],
                'fecha' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_UNICODE));
        } elseif (isset($responseToken2['descripcion'])) {
            echo "   Descripción: " . $responseToken2['descripcion'] . "\n";
        }
    } else {
        echo "❌ ERROR HTTP: " . $resultToken2['code'] . "\n";
    }
}

echo "\n4. PRUEBA 3: CON FECHA INVERTIDA (MMYY)\n";

// Probar con fecha invertida (por si acaso)
$datosInvertido = [
    'track2' => '5267772159330969=3111',  // MMYY en lugar de YYMM
    'amount' => '2.50'
];

echo "   Probando con fecha MMYY:\n";
echo "   - track2: " . substr($datosInvertido['track2'], 0, 6) . "****" . substr($datosInvertido['track2'], -4) . "\n";

$encrypted3 = encryptDataAES($datosInvertido, $config['clave'], $config['vector']);

if ($encrypted3) {
    $bodyTokenizacion3 = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted3
        ],
        'method' => 'getTokenize'
    ]);
    
    echo "   Enviando solicitud... ";
    $resultToken3 = makeRequest($config['api_url'], $headers, $bodyTokenizacion3);
    
    if ($resultToken3['code'] == 200) {
        $responseToken3 = json_decode($resultToken3['body'], true);
        echo "✅ RESPUESTA\n";
        echo "   Código: " . ($responseToken3['codigo'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($responseToken3['mensaje'] ?? $responseToken3['msg'] ?? '') . "\n";
    }
}

echo "\n========================================\n";
echo "VERIFICACIÓN DE CLAVE Y VECTOR\n";
echo "========================================\n";

echo "Clave proporcionada: '" . $config['clave'] . "'\n";
echo "Longitud clave: " . strlen($config['clave']) . " caracteres\n";
echo "Bytes UTF8: " . bin2hex($config['clave']) . "\n\n";

echo "Vector proporcionado: '" . $config['vector'] . "'\n";
echo "Longitud vector: " . strlen($config['vector']) . " caracteres\n";
echo "Bytes UTF8: " . bin2hex($config['vector']) . "\n\n";

echo "NOTA: En C# hacen: Encoding.UTF8.GetBytes('" . $config['clave'] . "')\n";
echo "Esto produce bytes: " . bin2hex($config['clave']) . "\n";
echo "NO deben aplicar padding ni truncar.\n";

echo "\n========================================\n";
echo "RESUMEN\n";
echo "========================================\n";

echo "Si la Prueba 1 (ejemplo doc) funciona pero la 2 (tu tarjeta) no:\n";
echo "1. La tarjeta 5267772159330969 podría no permitir tokenización\n";
echo "2. El formato de fecha podría ser MMYY en lugar de YYMM\n";
echo "3. El monto $2.50 podría ser muy bajo\n\n";

echo "Si NINGUNA funciona:\n";
echo "1. La encriptación aún podría tener problemas\n";
echo "2. Necesitamos ver el encrypt generado para comparar\n";