<?php
// 
// test-pago-autodiscover.php
// Prueba automática para encontrar el método de pago correcto
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
        CURLOPT_TIMEOUT => 10 // Más rápido para pruebas
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'body' => $response];
}

// ============================================
// PRUEBA AUTOMÁTICA
// ============================================

echo "========================================\n";
echo "BÚSQUEDA AUTOMÁTICA DE MÉTODO DE PAGO\n";
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

$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

if ($result['code'] != 200) {
    echo "❌ ERROR: " . $result['code'] . "\n";
    exit;
}

$response = json_decode($result['body'], true);
$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "❌ No se obtuvo token\n";
    exit;
}

echo "✅ Token Cliente: " . substr($tokenCliente, 0, 20) . "...\n\n";

// 2. Preparar datos de pago
echo "2. PREPARANDO DATOS DE PAGO\n";
$cav = 'TEST' . date('YmdHis') . rand(100, 999);

$datosPago = [
    'track2' => $tokenTarjeta,
    'amount' => '1.00', // Monto mínimo
    'cvv' => '',
    'cav' => $cav,
    'msi' => 0,
    'contrato' => '',
    'fiid_comercio' => '',
    'referencia' => 'TestFAMEDIC'
];

$encryptedPago = encryptDataAES($datosPago, $config['clave'], $config['vector']);
echo "   Datos encriptados preparados\n";
echo "   Monto: \$1.00 MXN\n";
echo "   CAV: $cav\n\n";

// 3. Lista de métodos a probar (priorizados)
$metodosAPrueba = [
    // Métodos más probables basados en patrones de API
    'sale' => 'Venta directa',
    'payment' => 'Pago simple',
    'charge' => 'Cargo',
    'chargeCard' => 'Cargo a tarjeta',
    'authorize' => 'Autorización',
    'authorizeCard' => 'Autorizar tarjeta',
    'purchase' => 'Compra',
    'processPayment' => 'Procesar pago',
    'processSale' => 'Procesar venta',
    'tokenSale' => 'Venta con token',
    'tokenCharge' => 'Cargo con token',
    'cardPayment' => 'Pago con tarjeta',
    'makePayment' => 'Realizar pago',
    'executePayment' => 'Ejecutar pago',
    'createPayment' => 'Crear pago',
    'createTransaction' => 'Crear transacción',
    'transaction' => 'Transacción',
    
    // Métodos con "get" (como los que funcionan)
    'getSale',
    'getPayment',
    'getCharge',
    'getAuthorize',
    'getPurchase',
    
    // Métodos alternativos
    'pay',
    'payToken',
    'tokenPay',
    'cardSale',
    'directSale',
    'simplePayment',
];

echo "3. PROBANDO MÉTODOS (esto tomará unos minutos)\n";
echo "   =======================================\n";

$metodosExitosos = [];

foreach ($metodosAPrueba as $key => $value) {
    if (is_numeric($key)) {
        $metodo = $value;
        $descripcion = $metodo;
    } else {
        $metodo = $key;
        $descripcion = $value;
    }
    
    echo "   • Probando: $metodo ($descripcion)... ";
    
    $bodyPago = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encryptedPago
        ],
        'method' => $metodo
    ]);
    
    $result = makeRequest($config['api_url'], $headers, $bodyPago);
    
    // Analizar respuesta
    if ($result['code'] == 200) {
        $response = json_decode($result['body'], true);
        
        // Verificar si es una respuesta válida de pago
        $esRespuestaValida = false;
        $razon = '';
        
        if (isset($response['codigo'])) {
            $esRespuestaValida = true;
            $razon = "Tiene código: " . $response['codigo'];
        } elseif (isset($response['error'])) {
            $razon = "Error: " . $response['error'];
        } elseif (isset($response['message'])) {
            $razon = "Mensaje: " . $response['message'];
        } elseif (isset($response['id'])) {
            $esRespuestaValida = true;
            $razon = "Tiene ID transacción";
        }
        
        if ($esRespuestaValida) {
            echo "✅ POSIBLE ÉXITO\n";
            echo "      $razon\n";
            $metodosExitosos[$metodo] = $response;
            
            // Mostrar más detalles si parece exitoso
            if (isset($response['codigo']) && $response['codigo'] == '00') {
                echo "      🎉 ¡PAGO APROBADO CON ESTE MÉTODO!\n";
                echo "      ID: " . ($response['id'] ?? 'N/A') . "\n";
                echo "      Auth: " . ($response['auth'] ?? 'N/A') . "\n";
            }
        } else {
            echo "⚠ Respuesta 200 pero no parece pago\n";
            echo "      $razon\n";
        }
    } elseif ($result['code'] == 404) {
        echo "❌ No existe (404)\n";
    } elseif ($result['code'] == 400) {
        echo "⚠ Bad Request (400) - Método podría existir\n";
        $response = json_decode($result['body'], true);
        echo "      " . ($response['error'] ?? $response['message'] ?? 'Error 400') . "\n";
    } elseif ($result['code'] == 422) {
        echo "⚠ Unprocessable Entity (422) - Método existe\n";
        $response = json_decode($result['body'], true);
        echo "      " . ($response['error'] ?? $response['message'] ?? 'Error 422') . "\n";
        $metodosExitosos[$metodo] = ['code' => 422, 'response' => $response];
    } else {
        echo "⚠ Código {$result['code']}\n";
    }
    
    // Pequeña pausa entre requests
    usleep(500000); // 0.5 segundos
}

// 4. Resultados
echo "\n4. RESULTADOS DE LA BÚSQUEDA\n";
echo "   ==========================\n";

if (count($metodosExitosos) > 0) {
    echo "✅ Se encontraron " . count($metodosExitosos) . " métodos potenciales:\n\n";
    
    foreach ($metodosExitosos as $metodo => $respuesta) {
        echo "   🔍 MÉTODO: $metodo\n";
        
        if (is_array($respuesta) && isset($respuesta['codigo'])) {
            echo "      Código: " . $respuesta['codigo'] . "\n";
            echo "      Mensaje: " . ($respuesta['mensaje'] ?? $respuesta['msg'] ?? 'N/A') . "\n";
            
            if (isset($respuesta['id'])) {
                echo "      ID Transacción: " . $respuesta['id'] . "\n";
            }
            
            if (isset($respuesta['auth'])) {
                echo "      Auth Code: " . $respuesta['auth'] . "\n";
            }
            
            // Guardar este método como candidato
            file_put_contents('metodo_pago_candidato.txt', 
                "Método: $metodo\n" .
                "Fecha: " . date('Y-m-d H:i:s') . "\n" .
                "Respuesta: " . json_encode($respuesta, JSON_PRETTY_PRINT) . "\n\n",
                FILE_APPEND
            );
        } else {
            echo "      Respuesta: " . json_encode($respuesta) . "\n";
        }
        echo "\n";
    }
    
    echo "   📝 Métodos candidatos guardados en 'metodo_pago_candidato.txt'\n";
} else {
    echo "❌ No se encontraron métodos de pago funcionales\n";
    echo "   Posibles causas:\n";
    echo "   1. El token de tarjeta no es válido\n";
    echo "   2. Los métodos de pago tienen otro nombre\n";
    echo "   3. Necesitas permisos especiales para pagos\n";
    echo "   4. El ambiente test no permite pagos reales\n";
}

// 5. Recomendaciones finales
echo "\n5. RECOMENDACIONES\n";
echo "   ==============\n";
echo "   1. Contacta URGENTE a EfevooPay y pregunta:\n";
echo "      '¿Cuál es el método exacto para procesar pagos con token?'\n";
echo "      '¿El método se llama getPayment, sale, charge u otro?'\n\n";
echo "   2. Pide documentación específica para pagos\n";
echo "   3. Verifica si necesitas habilitación especial\n";
echo "   4. Confirma si el ambiente test permite transacciones reales\n";

// 6. Probar con método getTokenize para confirmar que el token funciona
echo "\n6. VERIFICANDO QUE EL TOKEN FUNCIONE\n";
echo "   Probando método getTokenize (que sabemos funciona)... ";

$datosTest = [
    'track2' => $tokenTarjeta,
    'amount' => '0.01'
];

$encryptedTest = encryptDataAES($datosTest, $config['clave'], $config['vector']);

$bodyTest = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encryptedTest
    ],
    'method' => 'getTokenize'
]);

$resultTest = makeRequest($config['api_url'], $headers, $bodyTest);

if ($resultTest['code'] == 200) {
    $responseTest = json_decode($resultTest['body'], true);
    echo "✅ Token VÁLIDO\n";
    echo "   Código: " . ($responseTest['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($responseTest['mensaje'] ?? $responseTest['msg'] ?? 'N/A') . "\n";
    
    if (isset($responseTest['codigo']) && $responseTest['codigo'] == '14') {
        echo "   ⚠ El token podría estar expirado o inválido\n";
    }
} else {
    echo "❌ Error: " . $resultTest['code'] . "\n";
}

echo "\n========================================\n";
echo "PRÓXIMOS PASOS\n";
echo "========================================\n";
echo "1. Ejecuta el primer script para descubrir métodos\n";
echo "2. Contacta a EfevooPay para confirmar el método de pago\n";
echo "3. Prueba con montos mínimos (\$0.01) una vez tengas el método\n";
echo "4. Monitorea tu cuenta bancaria por cargos no deseados\n";