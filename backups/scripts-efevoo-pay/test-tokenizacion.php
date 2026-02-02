<?php
// test-tokenizacion.php

// ============================================
// CONFIGURACIÓN DIRECTA (sin dependencias)
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
// FUNCIONES AUXILIARES
// ============================================

/**
 * Genera código TOTP
 */
function generateTOTP($secret) {
    $timestamp = floor(time() / 30);
    
    // Decodificar Base32
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

/**
 * Genera hash HMAC-SHA256
 */
function generateHash($totp, $clave) {
    return base64_encode(hash_hmac('sha256', $clave, $totp, true));
}

/**
 * Encripta datos con AES-128-CBC
 */
function encryptData($data, $clave, $vector) {
    $plaintext = is_array($data) ? json_encode($data) : $data;
    
    // Asegurar que key e iv tengan 16 bytes
    $key = substr(str_pad($clave, 16, "\0"), 0, 16);
    $iv = substr(str_pad($vector, 16, "\0"), 0, 16);
    
    $ciphertext = openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    return base64_encode($ciphertext);
}

/**
 * Realiza una solicitud HTTP POST
 */
function makeRequest($url, $headers, $body) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response,
        'error' => $error
    ];
}

// ============================================
// PRUEBA PASO A PASO
// ============================================

echo "========================================\n";
echo "PRUEBA DE TOKENIZACIÓN EFEVOOPAY\n";
echo "========================================\n\n";

// Datos de la tarjeta de prueba
$tarjeta = '5328298801237748';
$expiracion = '0528'; // Octubre 2029 (formato YYMM)
$monto = '2.50'; // ≤ $3.00 MXN

echo "📋 DATOS DE LA PRUEBA:\n";
echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
echo "   Expiración: " . $expiracion . " (YYMM)\n";
echo "   Monto: $" . $monto . " MXN\n";
echo "   Cliente: " . $config['cliente'] . "\n";
echo "\n";

// ============================================
// PASO 1: OBTENER TOKEN DE CLIENTE
// ============================================

echo "🔑 PASO 1: OBTENIENDO TOKEN DE CLIENTE\n";
echo "   Generando TOTP... ";

$totp = generateTOTP($config['totp_secret']);
echo "✅ " . $totp . "\n";

echo "   Generando hash... ";
$hash = generateHash($totp, $config['clave']);
echo "✅ " . substr($hash, 0, 20) . "...\n";

// Preparar solicitud para token de cliente
$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

$body = json_encode([
    'payload' => [
        'hash' => $hash,
        'cliente' => $config['cliente']
    ],
    'method' => 'getClientToken'
]);

echo "   Enviando solicitud... ";
$result = makeRequest($config['api_url'], $headers, $body);

if ($result['code'] == 200) {
    $response = json_decode($result['body'], true);
    
    if (isset($response['codigo']) && $response['codigo'] == '100') {
        $tokenCliente = $response['token'];
        echo "✅ ÉXITO\n";
        echo "   Token: " . substr($tokenCliente, 0, 30) . "...\n";
        echo "   Duración: " . $response['duracion'] . "\n";
        echo "   Mensaje: " . $response['msg'] . "\n";
    } else {
        echo "❌ ERROR\n";
        echo "   Código: " . ($response['codigo'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($response['msg'] ?? 'Error desconocido') . "\n";
        exit;
    }
} else {
    echo "❌ ERROR HTTP\n";
    echo "   Código: " . $result['code'] . "\n";
    echo "   Error: " . $result['error'] . "\n";
    echo "   Respuesta: " . $result['body'] . "\n";
    exit;
}

echo "\n";

// ============================================
// PASO 2: TOKENIZAR LA TARJETA
// ============================================

echo "💳 PASO 2: TOKENIZANDO LA TARJETA\n";

// Preparar datos para encriptar
$datosTarjeta = [
    'track2' => $tarjeta . '=' . $expiracion,
    'amount' => $monto
];

echo "   Datos a encriptar:\n";
echo "   - track2: " . $tarjeta . '=' . $expiracion . "\n";
echo "   - amount: " . $monto . "\n";

echo "   Encriptando datos... ";
$datosEncriptados = encryptData($datosTarjeta, $config['clave'], $config['vector']);
echo "✅ " . substr($datosEncriptados, 0, 20) . "...\n";

// Preparar solicitud para tokenización
$bodyTokenizacion = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $datosEncriptados
    ],
    'method' => 'getTokenize'
]);

echo "   Enviando solicitud de tokenización... ";
$resultTokenizacion = makeRequest($config['api_url'], $headers, $bodyTokenizacion);

if ($resultTokenizacion['code'] == 200) {
    $responseTokenizacion = json_decode($resultTokenizacion['body'], true);
    
    echo "✅ RESPUESTA RECIBIDA\n\n";
    
    // Mostrar respuesta completa
    echo "📄 RESPUESTA COMPLETA:\n";
    echo json_encode($responseTokenizacion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Análisis de la respuesta
    echo "🔍 ANÁLISIS DE LA RESPUESTA:\n";
    
    if (isset($responseTokenizacion['codigo'])) {
        echo "   Código: " . $responseTokenizacion['codigo'] . "\n";
        
        // Interpretar códigos comunes
        $codigos = [
            '100' => '✅ Operación exitosa',
            '102' => '❌ Credenciales incorrectas',
            '103' => '❌ Token inválido o expirado',
            '104' => '❌ Error en los datos proporcionados',
            '105' => '❌ Tarjeta rechazada',
            '106' => '❌ Fondos insuficientes',
            '107' => '❌ Tarjeta bloqueada',
            '108' => '❌ Límite excedido',
            '109' => '❌ Error en el procesador de pagos',
            '110' => '❌ Timeout en la operación'
        ];
        
        if (isset($codigos[$responseTokenizacion['codigo']])) {
            echo "   Significado: " . $codigos[$responseTokenizacion['codigo']] . "\n";
        }
    }
    
    if (isset($responseTokenizacion['msg'])) {
        echo "   Mensaje: " . $responseTokenizacion['msg'] . "\n";
    }
    
    if (isset($responseTokenizacion['token'])) {
        echo "   ✅ Token de tarjeta obtenido:\n";
        echo "   " . $responseTokenizacion['token'] . "\n";
        echo "   Longitud: " . strlen($responseTokenizacion['token']) . " caracteres\n";
        
        // Guardar token en archivo para uso posterior
        file_put_contents('token_tarjeta.txt', $responseTokenizacion['token']);
        echo "   📝 Token guardado en 'token_tarjeta.txt'\n";
    } else {
        echo "   ⚠ No se recibió token de tarjeta en la respuesta\n";
    }
    
    // Mostrar otros datos importantes
    $camposImportantes = ['id', 'reference', 'status', 'approved', 'authorization', 'transaction_id'];
    foreach ($camposImportantes as $campo) {
        if (isset($responseTokenizacion[$campo])) {
            echo "   " . ucfirst($campo) . ": " . $responseTokenizacion[$campo] . "\n";
        }
    }
    
} else {
    echo "❌ ERROR HTTP\n";
    echo "   Código: " . $resultTokenizacion['code'] . "\n";
    echo "   Error: " . $resultTokenizacion['error'] . "\n";
    echo "   Respuesta: " . $resultTokenizacion['body'] . "\n";
}

echo "\n";

// ============================================
// PASO 3: VERIFICAR ARCHIVO DE TOKEN
// ============================================

echo "📁 PASO 3: VERIFICANDO ARCHIVOS\n";

if (file_exists('token_tarjeta.txt')) {
    $tokenGuardado = file_get_contents('token_tarjeta.txt');
    echo "   ✅ Archivo 'token_tarjeta.txt' creado\n";
    echo "   Token guardado: " . substr($tokenGuardado, 0, 30) . "...\n";
} else {
    echo "   ⚠ No se creó archivo de token\n";
}

// ============================================
// RESUMEN FINAL
// ============================================

echo "\n";
echo "========================================\n";
echo "RESUMEN DE LA PRUEBA\n";
echo "========================================\n";

$timestamp = date('Y-m-d H:i:s');
echo "   Fecha y hora: " . $timestamp . "\n";
echo "   Tarjeta probada: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
echo "   Monto: $" . $monto . " MXN\n";
echo "   Token cliente: " . (isset($tokenCliente) ? "✅ Obtenido" : "❌ No obtenido") . "\n";
echo "   Token tarjeta: " . (isset($responseTokenizacion['token']) ? "✅ Obtenido" : "❌ No obtenido") . "\n";

if (isset($responseTokenizacion['codigo'])) {
    echo "   Código respuesta: " . $responseTokenizacion['codigo'] . "\n";
    
    // Evaluación final
    if ($responseTokenizacion['codigo'] == '100') {
        echo "\n🎉 ¡PRUEBA EXITOSA! La tarjeta fue tokenizada correctamente.\n";
    } else {
        echo "\n⚠ PRUEBA CON OBSERVACIONES. Revisa el código de respuesta.\n";
    }
}

echo "\n";
echo "========================================\n";
echo "PASOS SIGUIENTES\n";
echo "========================================\n";
echo "1. Si obtuviste token de tarjeta, puedes usarlo para procesar pagos\n";
echo "2. Para pagos con token, el campo 'cvv' debe ir vacío\n";
echo "3. El token tiene la misma vigencia que el token de cliente (1 año)\n";
echo "4. Guarda el token de forma segura en tu base de datos\n";