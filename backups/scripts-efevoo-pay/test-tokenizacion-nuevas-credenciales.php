<?php
// 
// test-tokenizacion-nuevas-credenciales.php

// ============================================
// CONFIGURACIÓN CON NUEVAS CREDENCIALES
// ============================================
$config = [
    'api_url' => 'https://intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay', // Verificar si este cambia
    'api_key' => '8BFCB46465F3418F', // Verificar si este cambia
    'totp_secret' => 'PIBOFBXR6P3TWXRFJQF5VRAMV5RFR3Y5',
    'clave' => '2NF2g75uJ4VXqJ7D',
    'cliente' => 'GFAMEDIC',
    'vector' => '1XGYCKGIneuhhGFq'
];

// ============================================
// FUNCIONES (MISMAS QUE ANTES)
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
// PRUEBA CON NUEVAS CREDENCIALES
// ============================================

echo "========================================\n";
echo "TOKENIZACIÓN - NUEVAS CREDENCIALES GFAMEDIC\n";
echo "========================================\n\n";

echo "⚠ NOTA: URL de producción/intgapi\n";
echo "   Usar tarjeta de PRUEBA sin cargos reales\n\n";

// 1. Obtener token de cliente
echo "1. OBTENIENDO TOKEN DE CLIENTE\n";
echo "   TOTP Secret: " . substr($config['totp_secret'], 0, 10) . "...\n";
echo "   Cliente: {$config['cliente']}\n";
echo "   URL: {$config['api_url']}\n\n";

$totp = generateTOTP($config['totp_secret']);
echo "   TOTP generado: $totp\n";

$hash = generateHash($totp, $config['clave']);
echo "   Hash generado: " . substr($hash, 0, 20) . "...\n";

$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

$bodyTokenCliente = json_encode([
    'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
    'method' => 'getClientToken'
]);

echo "   Enviando solicitud... ";
$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

echo "HTTP Code: " . $result['code'] . "\n";

if ($result['code'] != 200) {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    echo "   Respuesta: " . $result['body'] . "\n";
    
    // Verificar credenciales API_USER y API_KEY
    echo "\n⚠ Verifica si 'api_user' y 'api_key' también cambiaron\n";
    echo "   Actual: api_user = '{$config['api_user']}'\n";
    echo "   Actual: api_key = '" . substr($config['api_key'], 0, 10) . "...'\n";
    exit;
}

$response = json_decode($result['body'], true);
$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "❌ No se obtuvo token\n";
    echo "   Respuesta completa:\n";
    print_r($response);
    exit;
}

echo "✅ TOKEN OBTENIDO: " . substr($tokenCliente, 0, 20) . "...\n";
echo "   Duración: " . ($response['duracion'] ?? 'N/A') . "\n";

// 2. Probar tokenización con tarjeta de PRUEBA
echo "\n2. PROBANDO TOKENIZACIÓN CON TARJETA DE PRUEBA\n";

// Usar tarjeta de prueba (visa test)
$tarjeta = '4111111111111111'; // Tarjeta de prueba Visa
$expiracion = '1231'; // Diciembre 2031 (MMYY)
$montoMinimo = '0.01'; // Monto mínimo absoluto

echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
echo "   Expiración: $expiracion (MMYY)\n";
echo "   Monto: $$montoMinimo MXN (mínimo para pruebas)\n\n";

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
echo "✅ (" . strlen($encrypted) . " chars)\n";

$bodyTokenizacion = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encrypted
    ],
    'method' => 'getTokenize'
]);

echo "   Enviando solicitud de tokenización... ";
$resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion);

echo "HTTP Code: " . $resultToken['code'] . "\n";

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
        case '100':
            echo "\n   🎉 ¡APROBADO! Transacción exitosa\n";
            if (isset($responseToken['token_usuario'])) {
                echo "   Token de usuario: " . $responseToken['token_usuario'] . "\n";
                file_put_contents('token_gfamedic.txt', $responseToken['token_usuario']);
                echo "   📝 Token guardado en 'token_gfamedic.txt'\n";
            }
            if (isset($responseToken['token'])) {
                echo "   Token: " . $responseToken['token'] . "\n";
            }
            if (isset($responseToken['numref'])) {
                echo "   Referencia: " . $responseToken['numref'] . "\n";
            }
            if (isset($responseToken['id'])) {
                echo "   ID Transacción: " . $responseToken['id'] . "\n";
            }
            break;
            
        case '30':
            echo "\n   ❌ ERROR DE FORMATO\n";
            echo "   Revisa el formato de los datos (tarjeta=expiracion)\n";
            break;
            
        case '05':
            echo "\n   ❌ NO HONRAR - Tarjeta rechazada\n";
            echo "   El banco no aprobó la transacción\n";
            break;
            
        case '51':
            echo "\n   ❌ FONDOS INSUFICIENTES\n";
            echo "   La tarjeta no tiene fondos suficientes\n";
            break;
            
        case '54':
            echo "\n   ❌ TARJETA VENCIDA\n";
            echo "   Revisa la fecha de expiración\n";
            break;
            
        case '57':
            echo "\n   ❌ TRANSACCIÓN NO PERMITIDA\n";
            echo "   Esta tarjeta no permite este tipo de transacción\n";
            break;
            
        case '61':
            echo "\n   ⚠ MONTO EXCEDE LÍMITE\n";
            echo "   Intenta con un monto menor\n";
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

echo "\n3. BUSCAR TRANSACCIONES RECIENTES\n";

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
        
        if (count($responseBusqueda['data']) > 0) {
            echo "\n   ÚLTIMAS 3 TRANSACCIONES:\n";
            $counter = 0;
            foreach ($responseBusqueda['data'] as $trans) {
                if ($counter >= 3) break;
                echo "   -------------------------\n";
                echo "   ID: " . ($trans['ID'] ?? $trans['id'] ?? 'N/A') . "\n";
                echo "   Monto: $" . ($trans['amount'] ?? $trans['monto'] ?? 'N/A') . "\n";
                echo "   Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                echo "   Estado: " . ($trans['approved'] ?? $trans['status'] ?? 'N/A') . "\n";
                echo "   Tipo: " . ($trans['type'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                if (isset($trans['concept'])) {
                    echo "   Concepto: " . $trans['concept'] . "\n";
                }
                $counter++;
            }
        }
    } else {
        echo "⚠ No se encontraron transacciones\n";
        if (isset($responseBusqueda['descripcion'])) {
            echo "   Descripción: " . $responseBusqueda['descripcion'] . "\n";
        }
    }
} else {
    echo "❌ Error al buscar transacciones (HTTP {$resultBusqueda['code']})\n";
}

echo "\n========================================\n";
echo "RESUMEN CONFIGURACIÓN GFAMEDIC\n";
echo "========================================\n";

echo "✓ API URL: {$config['api_url']}\n";
echo "✓ Cliente: {$config['cliente']}\n";
echo "✓ TOTP Secret: " . substr($config['totp_secret'], 0, 10) . "...\n";
echo "✓ Clave AES: " . substr($config['clave'], 0, 6) . "...\n";
echo "✓ Vector AES: " . substr($config['vector'], 0, 6) . "...\n";
echo "✓ API User: {$config['api_user']}\n";
echo "✓ API Key: " . substr($config['api_key'], 0, 10) . "...\n\n";

echo "========================================\n";
echo "SIGUIENTES PASOS\n";
echo "========================================\n";

echo "1. Verificar si 'api_user' y 'api_key' también cambiaron\n";
echo "2. Probar con diferentes tarjetas de prueba:\n";
echo "   - Visa: 4111111111111111\n";
echo "   - MasterCard: 5555555555554444\n";
echo "   - AMEX: 378282246310005\n";
echo "3. Probar diferentes montos:\n";
echo "   - $0.01 MXN (mínimo)\n";
echo "   - $1.00 MXN\n";
echo "   - $10.00 MXN\n";
echo "4. Contactar a EfevooPay si hay errores persistentes\n";

// Guardar configuración para referencia
file_put_contents('config_gfamedic.json', json_encode($config, JSON_PRETTY_PRINT));
echo "\n📁 Configuración guardada en 'config_gfamedic.json'\n";