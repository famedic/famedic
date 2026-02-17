<?php
// 
// test-pago-con-token-existente.php
// Prueba de pago usando token ya existente
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

// Token de tarjeta proporcionado
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

function saveTransactionLog($data) {
    $timestamp = date('Y-m-d H:i:s');
    $logData = "========== [$timestamp] ==========\n";
    $logData .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    file_put_contents('log_transacciones.txt', $logData, FILE_APPEND);
}

// ============================================
// PRUEBA DE PAGO CON TOKEN EXISTENTE
// ============================================

echo "========================================\n";
echo "PRUEBA DE PAGO CON TOKEN EXISTENTE\n";
echo "========================================\n\n";

echo "🔑 TOKEN DE TARJETA:\n";
echo "   " . $tokenTarjeta . "\n";
echo "   Longitud: " . strlen($tokenTarjeta) . " caracteres\n\n";

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

echo "   Enviando solicitud... ";
$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

if ($result['code'] != 200) {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    echo "   Respuesta: " . $result['body'] . "\n";
    exit;
}

$response = json_decode($result['body'], true);
$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "❌ No se obtuvo token de cliente\n";
    print_r($response);
    exit;
}

echo "✅ ÉXITO\n";
echo "   Token Cliente: " . substr($tokenCliente, 0, 30) . "...\n\n";

// 2. Preparar datos para pago de $3.00 MXN
echo "2. PREPARANDO PAGO DE \$3.00 MXN\n";

// Generar CAV único (Transaction ID)
$cav = 'PAY' . date('YmdHis') . rand(100, 999);

$datosPago = [
    'track2' => $tokenTarjeta, // Token en lugar del track2
    'amount' => '3.00', // Monto de $3.00 MXN
    'cvv' => '', // Vacío cuando se usa token
    'cav' => $cav,
    'msi' => 0, // Sin meses sin intereses
    'contrato' => '', // Vacío si no es pago recurrente
    'fiid_comercio' => '', // Dejar vacío o usar el proporcionado por Efevoo
    'referencia' => 'TestFAMEDIC' // Nombre del comercio
];

echo "   📋 DETALLES DEL PAGO:\n";
echo "   • Monto: \$" . $datosPago['amount'] . " MXN\n";
echo "   • CAV (Transaction ID): " . $datosPago['cav'] . "\n";
echo "   • Referencia: " . $datosPago['referencia'] . "\n";
echo "   • Token (primeros 50 chars): " . substr($tokenTarjeta, 0, 50) . "...\n\n";

// Mostrar datos completos para debugging
echo "   📄 DATOS COMPLETOS A ENVIAR:\n";
echo json_encode($datosPago, JSON_PRETTY_PRINT) . "\n";

echo "   ¿Continuar con el pago? (s/n): ";
$handle = fopen("php://stdin", "r");
$confirm = trim(fgets($handle));
fclose($handle);

if (strtolower($confirm) != 's') {
    echo "   ❌ Pago cancelado por usuario\n";
    exit;
}

// 3. Encriptar datos
echo "\n3. ENCRIPTANDO DATOS... ";
$encryptedPago = encryptDataAES($datosPago, $config['clave'], $config['vector']);
echo "✅\n";
echo "   Longitud encrypted: " . strlen($encryptedPago) . " caracteres\n";

// 4. Enviar solicitud de pago
echo "\n4. ENVIANDO SOLICITUD DE PAGO...\n";

$bodyPago = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encryptedPago
    ],
    'method' => 'getPayment'
]);

echo "   Enviando a API... ";
$resultPago = makeRequest($config['api_url'], $headers, $bodyPago);

// 5. Procesar respuesta
echo "✅ RESPUESTA RECIBIDA\n\n";

if ($resultPago['code'] == 200) {
    $responsePago = json_decode($resultPago['body'], true);
    
    // Guardar log
    saveTransactionLog([
        'tipo' => 'PAGO_CON_TOKEN_EXISTENTE',
        'fecha' => date('Y-m-d H:i:s'),
        'token_tarjeta_corto' => substr($tokenTarjeta, 0, 30) . '...',
        'monto' => '3.00',
        'cav' => $cav,
        'respuesta' => $responsePago
    ]);
    
    echo "📊 RESULTADO DEL PAGO:\n";
    echo "════════════════════════════════════════\n";
    
    // Mostrar información clave
    $camposImportantes = [
        'codigo' => 'Código',
        'mensaje' => 'Mensaje',
        'msg' => 'Mensaje (msg)',
        'id' => 'ID Transacción',
        'auth' => 'Código Autorización',
        'reference' => 'Referencia',
        'descripcion' => 'Descripción',
        'transaccion' => 'Transacción',
        'folio' => 'Folio',
        'estado' => 'Estado'
    ];
    
    foreach ($camposImportantes as $key => $label) {
        if (isset($responsePago[$key])) {
            echo "   " . $label . ": " . $responsePago[$key] . "\n";
        }
    }
    
    // Interpretar códigos específicos
    $codigo = $responsePago['codigo'] ?? '';
    
    echo "\n🔍 INTERPRETACIÓN:\n";
    echo "   Código: " . $codigo . " - ";
    
    switch($codigo) {
        case '00':
            echo "🎉 ¡PAGO APROBADO!\n";
            echo "   La transacción se completó exitosamente\n";
            
            // Guardar información importante
            if (isset($responsePago['id'])) {
                file_put_contents('ultima_transaccion.txt', 
                    "ID: " . $responsePago['id'] . "\n" .
                    "Monto: 3.00\n" .
                    "Fecha: " . date('Y-m-d H:i:s') . "\n" .
                    "CAV: " . $cav . "\n" .
                    "Auth: " . ($responsePago['auth'] ?? 'N/A') . "\n"
                );
                echo "   📝 Detalles guardados en 'ultima_transaccion.txt'\n";
            }
            break;
            
        case '05':
            echo "❌ NO HONRAR - Tarjeta rechazada\n";
            echo "   El banco no aprobó la transacción\n";
            break;
            
        case '14':
            echo "❌ TARJETA INVÁLIDA\n";
            echo "   El token de tarjeta no es válido o está expirado\n";
            break;
            
        case '30':
            echo "❌ ERROR DE FORMATO\n";
            echo "   Revisa el formato de los datos enviados\n";
            break;
            
        case '51':
            echo "❌ FONDOS INSUFICIENTES\n";
            echo "   La tarjeta no tiene fondos suficientes\n";
            break;
            
        case '54':
            echo "❌ TARJETA VENCIDA\n";
            echo "   La tarjeta ha expirado\n";
            break;
            
        case '55':
            echo "❌ CLAVE INCORRECTA\n";
            echo "   El CVV es incorrecto\n";
            break;
            
        default:
            echo "⚠ Código no documentado\n";
            echo "   Contacta a EfevooPay para más información\n";
    }
    
    // Mostrar respuesta completa para debugging
    echo "\n📄 RESPUESTA COMPLETA (JSON):\n";
    echo json_encode($responsePago, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Guardar respuesta completa en archivo
    file_put_contents('respuesta_pago_' . date('Ymd_His') . '.json', 
        json_encode($responsePago, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n\n   📁 Respuesta completa guardada en: respuesta_pago_" . date('Ymd_His') . ".json\n";
    
} else {
    echo "❌ ERROR HTTP: " . $resultPago['code'] . "\n";
    echo "   Respuesta del servidor:\n";
    echo "   " . $resultPago['body'] . "\n";
}

// 6. Buscar transacciones recientes
echo "\n5. BUSCANDO TRANSACCIONES RECIENTES\n";

$bodyBusqueda = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'range1' => date('Y-m-d 00:00:00'),
        'range2' => date('Y-m-d 23:59:59')
    ],
    'method' => 'getTranSearch'
]);

echo "   Consultando transacciones del día... ";
$resultBusqueda = makeRequest($config['api_url'], $headers, $bodyBusqueda);

if ($resultBusqueda['code'] == 200) {
    $responseBusqueda = json_decode($resultBusqueda['body'], true);
    
    if (isset($responseBusqueda['data']) && is_array($responseBusqueda['data'])) {
        $transacciones = $responseBusqueda['data'];
        echo "✅ " . count($transacciones) . " transacciones encontradas\n";
        
        // Filtrar por monto $3.00
        $transaccionesFiltradas = array_filter($transacciones, function($trans) {
            return ($trans['amount'] ?? 0) == '3.00' || 
                   ($trans['amount'] ?? 0) == 3.00 ||
                   (isset($trans['reference']) && strpos($trans['reference'], 'TestFAMEDIC') !== false);
        });
        
        if (count($transaccionesFiltradas) > 0) {
            echo "\n📋 TRANSACCIONES DE \$3.00 ENCONTRADAS:\n";
            foreach ($transaccionesFiltradas as $trans) {
                echo "   ---------------------------------\n";
                echo "   ID: " . ($trans['ID'] ?? $trans['id'] ?? 'N/A') . "\n";
                echo "   Monto: $" . ($trans['amount'] ?? 'N/A') . "\n";
                echo "   Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                echo "   Estado: " . ($trans['approved'] ?? $trans['status'] ?? 'N/A') . "\n";
                echo "   Tipo: " . ($trans['type'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                echo "   Referencia: " . ($trans['reference'] ?? 'N/A') . "\n";
                if (isset($trans['auth'])) echo "   Auth: " . $trans['auth'] . "\n";
                echo "\n";
            }
        } else {
            echo "\nℹ️ No se encontraron transacciones de \$3.00\n";
            echo "   (Puede tardar unos minutos en aparecer)\n";
        }
    } else {
        echo "ℹ️ No hay transacciones hoy\n";
    }
} else {
    echo "⚠ Error al buscar transacciones\n";
}

// 7. Verificar si es posible hacer devolución
if (isset($responsePago) && ($responsePago['codigo'] ?? '') == '00' && isset($responsePago['id'])) {
    echo "\n6. ¿DESEAS HACER UNA DEVOLUCIÓN?\n";
    echo "   ID Transacción: " . $responsePago['id'] . "\n";
    echo "   Monto a devolver: \$3.00 MXN\n\n";
    echo "   ¿Realizar devolución? (s/n): ";
    
    $handle = fopen("php://stdin", "r");
    $confirmRefund = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($confirmRefund) == 's') {
        echo "\n   ⚠ IMPORTANTE: Para devolución necesitas:\n";
        echo "   1. Confirmar con EfevooPay el método correcto (probablemente 'getRefund')\n";
        echo "   2. Estructura exacta de los datos para devolución\n";
        echo "   3. Si el ambiente test permite devoluciones\n\n";
        
        echo "   📋 Datos necesarios para devolución:\n";
        echo "   • token: " . substr($tokenCliente, 0, 20) . "...\n";
        echo "   • track2: " . substr($tokenTarjeta, 0, 30) . "...\n";
        echo "   • amount: 3.00\n";
        echo "   • transaction_id: " . $responsePago['id'] . "\n";
        echo "   • Nuevo CAV para la devolución\n";
    }
}

echo "\n========================================\n";
echo "RESUMEN DE LA PRUEBA\n";
echo "========================================\n";

echo "✅ Token Cliente obtenido\n";
echo "✅ Token Tarjeta utilizado: " . substr($tokenTarjeta, 0, 30) . "...\n";
echo "✅ Monto intentado: \$3.00 MXN\n";

if (isset($responsePago)) {
    echo "✅ Código respuesta: " . ($responsePago['codigo'] ?? 'N/A') . "\n";
    if (isset($responsePago['id'])) {
        echo "✅ ID Transacción: " . $responsePago['id'] . "\n";
    }
    if (isset($responsePago['auth'])) {
        echo "✅ Auth Code: " . $responsePago['auth'] . "\n";
    }
}

echo "\n⚠ RECOMENDACIONES:\n";
echo "1. Verifica en tu estado de cuenta si hubo cargo real\n";
echo "2. Para pruebas, usa montos como \$0.01 o \$1.00\n";
echo "3. Contacta a EfevooPay si el token no funciona\n";
echo "4. Guarda los logs para referencia futura\n";

echo "\n📁 ARCHIVOS GENERADOS:\n";
echo "   • log_transacciones.txt - Log de todas las transacciones\n";
if (isset($responsePago['id'])) {
    echo "   • ultima_transaccion.txt - Detalles de la última transacción\n";
}
echo "   • respuesta_pago_*.json - Respuestas completas en JSON\n";