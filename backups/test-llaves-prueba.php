<?php

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
// FUNCIONES
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

function encryptDataAES($data, $clave, $vector) {
    $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
    return base64_encode(openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $clave,
        OPENSSL_RAW_DATA,
        $vector
    ));
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
// PRUEBA DEFINITIVA
// ============================================

echo "========================================\n";
echo "TOKENIZACIÓN FINAL - PRUEBA SEGURA\n";
echo "========================================\n\n";

echo "⚠ ADVERTENCIA: Parece que la tokenización hace cargos reales\n";
echo "   Usaremos monto MÍNIMO posible\n\n";

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

if ($result['code'] != 200) {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    exit;
}

$response = json_decode($result['body'], true);
$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "❌ No se obtuvo token\n";
    exit;
}

echo "✅ ÉXITO\n";

// 2. Probar tokenización con montos MÍNIMOS
echo "\n2. PROBANDO TOKENIZACIÓN CON MONTO MÍNIMO\n";

// Formato CORRECTO descubierto: MMYY (3111 = Noviembre 2031)
$tarjeta = '5267772159330969';
$expiracion = '3111'; // YYMM - ¡CORRECTO!
$montoMinimo = '1.50'; // El mínimo absoluto

echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
echo "   Expiración: $expiracion (MMYY)\n";
echo "   Monto: $$montoMinimo MXN (mínimo posible)\n\n";

echo "   ¿Continuar? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) != 's') {
    echo "   Cancelado por usuario\n";
    exit;
}

$datos = [
    'track2' => $tarjeta . '=' . $expiracion,
    'amount' => $montoMinimo
];

echo "   Encriptando... ";
$encrypted = encryptDataAES($datos, $config['clave'], $config['vector']);
echo "✅\n";

$bodyTokenizacion = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encrypted
    ],
    'method' => 'getTokenize'
]);

echo "   Enviando solicitud de tokenización... ";
$resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion);

if ($resultToken['code'] == 200) {
    $responseToken = json_decode($resultToken['body'], true);
    
    echo "✅ RESPUESTA RECIBIDA\n\n";
    
    echo "📊 RESULTADO:\n";
    echo "   Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($responseToken['mensaje'] ?? $responseToken['msg'] ?? 'Sin mensaje') . "\n";
    
    if (isset($responseToken['descripcion'])) {
        echo "   Descripción: " . $responseToken['descripcion'] . "\n";
    }
    
    // Interpretar códigos
    $codigo = $responseToken['codigo'] ?? '';
    
    switch($codigo) {
        case '00':
            echo "\n   🎉 ¡APROBADO! Transacción exitosa\n";
            if (isset($responseToken['token'])) {
                echo "   Token obtenido: " . $responseToken['token'] . "\n";
                file_put_contents('token_tarjeta_final.txt', $responseToken['token']);
                echo "   📝 Token guardado en 'token_tarjeta_final.txt'\n";
            }
            break;
            
        case '30':
            echo "\n   ❌ ERROR DE FORMATO\n";
            echo "   Revisa el formato de los datos\n";
            break;
            
        case '05':
            echo "\n   ❌ NO HONRAR - Tarjeta rechazada\n";
            echo "   El banco no aprobó la transacción\n";
            break;
            
        default:
            echo "\n   ⚠ Código no reconocido: $codigo\n";
    }
    
    // Mostrar TODA la respuesta para debugging
    echo "\n📄 RESPUESTA COMPLETA:\n";
    print_r($responseToken);
    
} else {
    echo "❌ ERROR HTTP: " . $resultToken['code'] . "\n";
    echo "   Respuesta: " . $resultToken['body'] . "\n";
}

echo "\n3. BUSCAR LA TRANSACCIÓN\n";

// Buscar transacciones recientes para ver si se registró
$bodyBusqueda = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'range1' => date('Y-m-d 00:00:00'),
        'range2' => date('Y-m-d 23:59:59')
    ],
    'method' => 'getTranSearch'
]);

echo "   Buscando transacciones de hoy... ";
$resultBusqueda = makeRequest($config['api_url'], $headers, $bodyBusqueda);

if ($resultBusqueda['code'] == 200) {
    $responseBusqueda = json_decode($resultBusqueda['body'], true);
    
    if (isset($responseBusqueda['data']) && is_array($responseBusqueda['data'])) {
        echo "✅ " . count($responseBusqueda['data']) . " transacciones encontradas\n";
        
        // Buscar la última transacción con monto similar
        foreach ($responseBusqueda['data'] as $trans) {
            if (($trans['amount'] ?? 0) == $montoMinimo || 
                ($trans['amount'] ?? 0) == 0.01) {
                echo "\n   📋 TRANSACCIÓN ENCONTRADA:\n";
                echo "   ID: " . ($trans['ID'] ?? $trans['id'] ?? 'N/A') . "\n";
                echo "   Monto: $" . ($trans['amount'] ?? 'N/A') . "\n";
                echo "   Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                echo "   Estado: " . ($trans['approved'] ?? $trans['status'] ?? 'N/A') . "\n";
                echo "   Tipo: " . ($trans['type'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                break;
            }
        }
    } else {
        echo "⚠ No se encontraron transacciones\n";
    }
} else {
    echo "❌ Error al buscar transacciones\n";
}
