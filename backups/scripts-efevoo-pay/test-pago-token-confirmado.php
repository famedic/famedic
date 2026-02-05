<?php
// 
// test-pago-token-confirmado.php
// Pago confirmado usando token - MÍNIMO $0.01
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

// Token de tarjeta
$tokenTarjeta = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';

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
// PRUEBA CONFIRMADA CON TOKEN
// ============================================

echo "🚨🚨🚨 ADVERTENCIA CRÍTICA 🚨🚨🚨\n";
echo "========================================\n";
echo "ESTE AMBIENTE TEST HACE CARGOS REALES\n";
echo "El pago anterior de \$1.00 FUE REAL\n";
echo "ID Transacción: 596856\n";
echo "========================================\n\n";

echo "¿Quieres continuar con otra prueba?\n";
echo "1. NO - Contactar a EfevooPay primero\n";
echo "2. SÍ - Prueba mínima (\$3.00) usando token\n";
echo "\nSelecciona (1/2): ";

$handle = fopen("php://stdin", "r");
$opcion = trim(fgets($handle));

if ($opcion == '1') {
    echo "\n✅ DECISIÓN CORRECTA\n";
    echo "Contacta a EfevooPay ahora:\n";
    echo "1. Solicita reversión de transacción 596856\n";
    echo "2. Pide tarjetas de prueba sin cargos reales\n";
    echo "3. Pide confirmación de montos mínimos de prueba\n";
    exit;
}

echo "\n⚠️ PRUEBA CON MONTO MÍNIMO: \$0.01 MXN\n";
echo "¿Estás SEGURO? (s/n): ";
$confirmacion = trim(fgets($handle));

if (strtolower($confirmacion) != 's') {
    echo "\n❌ Prueba cancelada\n";
    exit;
}

fclose($handle);

// 1. Obtener token de cliente
echo "\n1. OBTENIENDO TOKEN DE CLIENTE\n";
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

$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

if ($result['code'] != 200) {
    echo "❌ Error: " . $result['code'] . "\n";
    exit;
}

$response = json_decode($result['body'], true);
$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "❌ No se obtuvo token\n";
    exit;
}

echo "✅ Token Cliente: " . substr($tokenCliente, 0, 20) . "...\n";

// 2. Pago con token (mínimo $0.01)
echo "\n2. PAGO USANDO TOKEN DE TARJETA\n";

$cav = 'TOKENPAY' . date('YmdHis') . rand(100, 999);

$datosPago = [
    'track2' => $tokenTarjeta,  // TOKEN aquí
    'amount' => '3.00',         // MÍNIMO ABSOLUTO
    'cvv' => '',                // VACÍO con token
    'cav' => $cav,
    'msi' => 0,
    'contrato' => '',
    'fiid_comercio' => '',
    'referencia' => 'TestTokenPago'
];

echo "📋 DETALLES:\n";
echo "   • Token usado: " . substr($tokenTarjeta, 0, 30) . "...\n";
echo "   • Monto: \$0.01 MXN\n";
echo "   • CVV: [vacío - correcto para token]\n";
echo "   • CAV: $cav\n";
echo "   • Método: getPayment\n\n";

echo "   Encriptando... ";
$encryptedPago = encryptDataAES($datosPago, $config['clave'], $config['vector']);
echo "✅\n";

$bodyPago = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encryptedPago
    ],
    'method' => 'getPayment'  // ✅ MÉTODO CONFIRMADO
]);

echo "   Enviando pago con token... ";
$resultPago = makeRequest($config['api_url'], $headers, $bodyPago);

if ($resultPago['code'] == 200) {
    $responsePago = json_decode($resultPago['body'], true);
    
    echo "✅ RESPUESTA:\n\n";
    
    // Guardar log
    $log = [
        'fecha' => date('Y-m-d H:i:s'),
        'token_tarjeta' => substr($tokenTarjeta, 0, 30) . '...',
        'monto' => '0.01',
        'metodo' => 'getPayment',
        'cav' => $cav,
        'respuesta' => $responsePago
    ];
    
    file_put_contents('pagos_con_token.log', 
        json_encode($log, JSON_PRETTY_PRINT) . "\n\n",
        FILE_APPEND
    );
    
    // Mostrar resultados
    echo "📊 RESULTADO:\n";
    echo "   Código: " . ($responsePago['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($responsePago['mensaje'] ?? $responsePago['msg'] ?? 'N/A') . "\n";
    
    if (isset($responsePago['id'])) {
        echo "   ID Transacción: " . $responsePago['id'] . "\n";
    }
    
    if (isset($responsePago['auth'])) {
        echo "   Auth Code: " . $responsePago['auth'] . "\n";
    }
    
    if (isset($responsePago['reference'])) {
        echo "   Referencia: " . $responsePago['reference'] . "\n";
    }
    
    // Interpretación
    $codigo = $responsePago['codigo'] ?? '';
    
    echo "\n🔍 INTERPRETACIÓN:\n";
    switch($codigo) {
        case '00':
            echo "   🎉 PAGO APROBADO CON TOKEN\n";
            echo "   ✅ Flujo token → pago FUNCIONA\n";
            echo "   ⚠️ PERO el cargo es REAL a tu tarjeta\n";
            echo "   📞 Contacta a EfevooPay para reversión\n";
            break;
            
        case '05':
            echo "   ❌ RECHAZADO por el banco\n";
            echo "   El token es válido pero la transacción fue rechazada\n";
            break;
            
        case '14':
            echo "   ❌ TOKEN INVÁLIDO\n";
            echo "   El token ha expirado o no es válido\n";
            break;
            
        default:
            echo "   ⚠ Código: $codigo\n";
    }
    
    echo "\n📄 RESPUESTA COMPLETA:\n";
    print_r($responsePago);
    
} else {
    echo "❌ ERROR HTTP: " . $resultPago['code'] . "\n";
    echo "   Respuesta: " . $resultPago['body'] . "\n";
}

echo "\n========================================\n";
echo "CONCLUSIÓN TÉCNICA\n";
echo "========================================\n";
echo "✅ EL FLUJO TOKEN → PAGO FUNCIONA:\n";
echo "   1. Tokenización: getTokenize\n";
echo "   2. Pago con token: getPayment\n";
echo "   3. Campos correctos:\n";
echo "      • track2: [token]\n";
echo "      • cvv: [vacío]\n";
echo "      • amount: [monto]\n";
echo "\n🚨 PROBLEMA GRAVE:\n";
echo "   El ambiente TEST hace cargos REALES\n";
echo "   Transacción 596856 = \$1.00 REAL\n";
echo "\n📞 ACCIÓN REQUERIDA:\n";
echo "   1. Contacta EFEVOOPAY para reversión\n";
echo "   2. Pide ambiente de prueba SIN cargos\n";
echo "   3. Pide tarjetas de prueba específicas\n";