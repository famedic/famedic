<?php
// test-tokenizacion-productivo.php
// ============================================
// CONFIGURACIÓN PRODUCTIVO
//EFEVOO_FIID_COMERCIO=9890713
// ============================================
$config = [
    'api_url' => 'https://intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Famedic',
    'api_key' => '9e21f21d434ba4ab219a3cd3ad6c3171c142ece4ff87b0f12b4035106b22e162',
    'totp_secret' => 'PIBOFBXR6P3TWXRFJQF5VRAMV5RFR3Y5',
    'clave' => '2NF2g75uJ4VXqJ7D',
    'cliente' => 'GFAMEDIC',
    'vector' => '1XGYCKGIneuhhGFq',
    'idagep_empresa' => 1827
];

// ============================================
// FUNCIONES MEJORADAS CON DEBUG
// ============================================

function generateTOTP($secret) {
    echo "[DEBUG] Generando TOTP con secreto: " . substr($secret, 0, 5) . "...\n";
    
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
    
    $totp = str_pad($code, 6, '0', STR_PAD_LEFT);
    echo "[DEBUG] TOTP generado: $totp\n";
    return $totp;
}

function generateHash($totp, $clave) {
    $hash = base64_encode(hash_hmac('sha256', $clave, $totp, true));
    echo "[DEBUG] Hash generado: " . substr($hash, 0, 20) . "...\n";
    return $hash;
}

function encryptDataAES($data, $clave, $vector, $showDebug = false) {
    if ($showDebug) {
        echo "[DEBUG] Datos a encriptar: " . json_encode($data) . "\n";
    }
    
    $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
    $encrypted = openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $clave,
        OPENSSL_RAW_DATA,
        $vector
    );
    
    if ($encrypted === false) {
        echo "[ERROR] Fallo encriptación: " . openssl_error_string() . "\n";
        return false;
    }
    
    $result = base64_encode($encrypted);
    
    if ($showDebug) {
        echo "[DEBUG] Encriptado AES (primeros 50 chars): " . substr($result, 0, 50) . "...\n";
    }
    
    return $result;
}

function makeRequest($url, $headers, $body, $debug = true) {
    echo "[DEBUG] URL: $url\n";
    if ($debug) {
        echo "[DEBUG] Headers:\n";
        foreach ($headers as $header) {
            echo "  $header\n";
        }
        echo "[DEBUG] Body (primeros 200 chars): " . substr($body, 0, 200) . "...\n";
    }
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => $debug,
        CURLINFO_HEADER_OUT => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $requestInfo = curl_getinfo($ch);
    
    if ($debug) {
        echo "[DEBUG] HTTP Code: $httpCode\n";
        echo "[DEBUG] Request Headers:\n";
        $sentHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        echo $sentHeaders . "\n";
        
        echo "[DEBUG] Response raw (primeros 500 chars):\n";
        echo substr($response, 0, 500) . "...\n";
    }
    
    curl_close($ch);
    
    return [
        'code' => $httpCode, 
        'body' => $response,
        'info' => $requestInfo
    ];
}

// ============================================
// PRUEBA PRODUCTIVO CON DEBUG EXTENDIDO
// ============================================

echo "==================================================\n";
echo "TOKENIZACIÓN PRODUCTIVO - DEBUG EXTENDIDO\n";
echo "==================================================\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "Cliente: " . $config['cliente'] . "\n";
echo "ID Empresa: " . $config['idagep_empresa'] . "\n";
echo "==================================================\n\n";

// 1. Obtener token de cliente
echo "1. OBTENIENDO TOKEN DE CLIENTE\n";
echo "--------------------------------\n";

$totp = generateTOTP($config['totp_secret']);
$hash = generateHash($totp, $config['clave']);

$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

$bodyTokenCliente = json_encode([
    'payload' => [
        'hash' => $hash, 
        'cliente' => $config['cliente']
    ],
    'method' => 'getClientToken'
], JSON_PRETTY_PRINT);

echo "\n[INFO] Enviando solicitud token cliente...\n";
$result = makeRequest($config['api_url'], $headers, $bodyTokenCliente);

echo "\n[RESULTADO TOKEN CLIENTE]\n";
echo "HTTP Status: " . $result['code'] . "\n";

if ($result['code'] != 200) {
    echo "❌ ERROR HTTP: " . $result['code'] . "\n";
    echo "Respuesta cruda:\n" . $result['body'] . "\n";
    
    // Intentar decodificar JSON de error
    $errorJson = json_decode($result['body'], true);
    if ($errorJson) {
        echo "Error JSON decodificado:\n";
        print_r($errorJson);
    }
    exit;
}

$response = json_decode($result['body'], true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Error decodificando JSON: " . json_last_error_msg() . "\n";
    echo "Respuesta original:\n" . $result['body'] . "\n";
    exit;
}

echo "Respuesta JSON completa:\n";
print_r($response);

$tokenCliente = $response['token'] ?? null;

if (!$tokenCliente) {
    echo "\n❌ No se obtuvo token en la respuesta\n";
    
    // Buscar posibles errores
    if (isset($response['error'])) {
        echo "Error reportado: " . $response['error'] . "\n";
    }
    if (isset($response['mensaje'])) {
        echo "Mensaje: " . $response['mensaje'] . "\n";
    }
    exit;
}

echo "\n✅ TOKEN CLIENTE OBTENIDO\n";
echo "Token: " . substr($tokenCliente, 0, 50) . "...\n";
echo "Longitud: " . strlen($tokenCliente) . " caracteres\n";

// Guardar token para uso posterior
file_put_contents('token_cliente_productivo.txt', $tokenCliente);
echo "Token guardado en: token_cliente_productivo.txt\n";

// 2. Probar tokenización
echo "\n\n2. PRUEBA DE TOKENIZACIÓN\n";
echo "--------------------------------\n";

// Tarjetas de prueba - usar monto mínimo
$tarjetasPrueba = [
    [
        'numero' => '5267772159330969',
        'expiracion' => '3111', // MMYY
        'descripcion' => 'Prueba 2 - Monto mínimo'
    ]
];

foreach ($tarjetasPrueba as $idx => $tarjetaInfo) {
    $numeroTarjeta = $tarjetaInfo['numero'];
    $expiracion = $tarjetaInfo['expiracion'];
    $monto = '2.00'; // Monto ABSOLUTO mínimo
    
    echo "\n[PRUEBA " . ($idx + 1) . "] " . $tarjetaInfo['descripcion'] . "\n";
    echo "Tarjeta: " . substr($numeroTarjeta, 0, 6) . "****" . substr($numeroTarjeta, -4) . "\n";
    echo "Expiración: $expiracion (MMYY)\n";
    echo "Monto: $$monto MXN\n";
    
    // Preguntar confirmación
    echo "\n¿Ejecutar esta prueba? (s/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) != 's') {
        echo "Prueba saltada\n";
        continue;
    }
    
    // Preparar datos para tokenización
    $datosTokenizacion = [
        'track2' => $numeroTarjeta . '=' . $expiracion,
        'amount' => $monto
    ];
    
    echo "\n[DEBUG] Datos para tokenización:\n";
    print_r($datosTokenizacion);
    
    // Encriptar datos
    $encrypted = encryptDataAES($datosTokenizacion, $config['clave'], $config['vector'], true);
    
    if (!$encrypted) {
        echo "❌ Error en encriptación, saltando prueba\n";
        continue;
    }
    
    // Preparar solicitud
    $bodyTokenizacion = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'getTokenize'
    ], JSON_PRETTY_PRINT);
    
    echo "\n[INFO] Enviando solicitud de tokenización...\n";
    $resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion);
    
    echo "\n[RESULTADO TOKENIZACIÓN]\n";
    echo "HTTP Status: " . $resultToken['code'] . "\n";
    
    if ($resultToken['code'] == 200) {
        $responseToken = json_decode($resultToken['body'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Error decodificando JSON: " . json_last_error_msg() . "\n";
            echo "Respuesta original:\n" . $resultToken['body'] . "\n";
            continue;
        }
        
        echo "\n✅ RESPUESTA JSON RECIBIDA:\n";
        echo "----------------------------------------\n";
        
        // Mostrar respuesta de manera estructurada
        echo "CÓDIGO RESPUESTA:\n";
        echo "  Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
        echo "  Mensaje: " . ($responseToken['mensaje'] ?? $responseToken['msg'] ?? 'N/A') . "\n";
        
        if (isset($responseToken['descripcion'])) {
            echo "  Descripción: " . $responseToken['descripcion'] . "\n";
        }
        
        echo "\nDETALLES TÉCNICOS:\n";
        foreach ($responseToken as $key => $value) {
            if (!in_array($key, ['codigo', 'mensaje', 'msg', 'descripcion'])) {
                echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }
        
        // Interpretar código
        $codigo = $responseToken['codigo'] ?? '';
        echo "\nINTERPRETACIÓN:\n";
        
        switch($codigo) {
            case '00':
                echo "  🎉 APROBADO - Transacción exitosa\n";
                if (isset($responseToken['token'])) {
                    echo "  Token: " . $responseToken['token'] . "\n";
                    file_put_contents('token_tarjeta_productivo.txt', $responseToken['token']);
                    echo "  Token guardado en: token_tarjeta_productivo.txt\n";
                }
                if (isset($responseToken['token_usuario'])) {
                    echo "  Token Usuario: " . $responseToken['token_usuario'] . "\n";
                }
                break;
                
            case '30':
                echo "  ❌ ERROR DE FORMATO\n";
                echo "  Revisa el formato de track2 (tarjeta=expiracion)\n";
                break;
                
            case '05':
                echo "  ❌ NO HONRAR\n";
                echo "  Tarjeta rechazada por el banco\n";
                break;
                
            case '51':
                echo "  ❌ FONDOS INSUFICIENTES\n";
                break;
                
            case '54':
                echo "  ❌ TARJETA VENCIDA\n";
                break;
                
            default:
                echo "  ⚠ Código no reconocido: $codigo\n";
                echo "  Consulta documentación de EFEVOOPAY\n";
        }
        
        // Guardar respuesta completa
        $filename = 'respuesta_tokenizacion_' . date('Ymd_His') . '.json';
        file_put_contents($filename, json_encode($responseToken, JSON_PRETTY_PRINT));
        echo "\nRespuesta completa guardada en: $filename\n";
        
    } else {
        echo "❌ ERROR HTTP: " . $resultToken['code'] . "\n";
        echo "Respuesta cruda:\n" . $resultToken['body'] . "\n";
        
        // Intentar decodificar error
        $errorJson = json_decode($resultToken['body'], true);
        if ($errorJson) {
            echo "Error JSON:\n";
            print_r($errorJson);
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

// 3. Buscar transacciones recientes
echo "\n\n3. BUSCAR TRANSACCIONES RECIENTES\n";
echo "--------------------------------\n";

$bodyBusqueda = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'range1' => date('Y-m-d 00:00:00'),
        'range2' => date('Y-m-d 23:59:59'),
        'idagep_empresa' => $config['idagep_empresa']
    ],
    'method' => 'getTranSearch'
], JSON_PRETTY_PRINT);

echo "[INFO] Buscando transacciones de hoy...\n";
$resultBusqueda = makeRequest($config['api_url'], $headers, $bodyBusqueda);

echo "\n[RESULTADO BÚSQUEDA]\n";
echo "HTTP Status: " . $resultBusqueda['code'] . "\n";

if ($resultBusqueda['code'] == 200) {
    $responseBusqueda = json_decode($resultBusqueda['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ Error decodificando JSON: " . json_last_error_msg() . "\n";
        echo "Respuesta original:\n" . $resultBusqueda['body'] . "\n";
    } else {
        echo "\nESTADO DE LA CONSULTA:\n";
        
        if (isset($responseBusqueda['success']) && $responseBusqueda['success'] === true) {
            echo "✅ Consulta exitosa\n";
            
            if (isset($responseBusqueda['data']) && is_array($responseBusqueda['data'])) {
                $totalTransacciones = count($responseBusqueda['data']);
                echo "Transacciones encontradas: $totalTransacciones\n";
                
                if ($totalTransacciones > 0) {
                    echo "\nÚLTIMAS 5 TRANSACCIONES:\n";
                    echo str_repeat("-", 80) . "\n";
                    
                    $contador = 0;
                    foreach ($responseBusqueda['data'] as $trans) {
                        if ($contador >= 5) break;
                        
                        echo "Transacción #" . ($contador + 1) . "\n";
                        echo "  ID: " . ($trans['id'] ?? $trans['ID'] ?? 'N/A') . "\n";
                        echo "  Monto: $" . ($trans['amount'] ?? $trans['monto'] ?? 'N/A') . "\n";
                        echo "  Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                        echo "  Estado: " . ($trans['approved'] ?? $trans['status'] ?? $trans['estado'] ?? 'N/A') . "\n";
                        echo "  Código: " . ($trans['codigo'] ?? $trans['code'] ?? 'N/A') . "\n";
                        echo "  Descripción: " . ($trans['descripcion'] ?? $trans['description'] ?? 'N/A') . "\n";
                        echo "  Tipo: " . ($trans['type'] ?? $trans['tipo'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                        
                        if (isset($trans['tarjeta'])) {
                            echo "  Tarjeta: " . $trans['tarjeta'] . "\n";
                        }
                        
                        echo str_repeat("-", 80) . "\n";
                        $contador++;
                    }
                    
                    // Guardar transacciones
                    $filename = 'transacciones_' . date('Ymd_His') . '.json';
                    file_put_contents($filename, json_encode($responseBusqueda['data'], JSON_PRETTY_PRINT));
                    echo "Lista completa guardada en: $filename\n";
                }
            } else {
                echo "⚠ No se encontró el array 'data' en la respuesta\n";
                echo "Respuesta completa:\n";
                print_r($responseBusqueda);
            }
        } else {
            echo "❌ Consulta no exitosa\n";
            echo "Respuesta completa:\n";
            print_r($responseBusqueda);
        }
    }
} else {
    echo "❌ Error al buscar transacciones\n";
    echo "Respuesta: " . $resultBusqueda['body'] . "\n";
}

// 4. Prueba adicional: Verificar conexión simple
echo "\n\n4. PRUEBA DE CONEXIÓN BÁSICA\n";
echo "--------------------------------\n";

$testData = [
    'payload' => [
        'test' => 'conexion',
        'timestamp' => time()
    ],
    'method' => 'test'
];

$bodyTest = json_encode($testData);
echo "[INFO] Enviando prueba de conexión...\n";
$resultTest = makeRequest($config['api_url'], $headers, $bodyTest);

echo "\n[RESULTADO PRUEBA CONEXIÓN]\n";
echo "HTTP Status: " . $resultTest['code'] . "\n";

if ($resultTest['code'] == 200) {
    echo "✅ Conexión API exitosa\n";
    $responseTest = json_decode($resultTest['body'], true);
    if ($responseTest) {
        echo "Respuesta:\n";
        print_r($responseTest);
    }
} else {
    echo "❌ Problema de conexión\n";
    echo "Posibles causas:\n";
    echo "1. API URL incorrecta\n";
    echo "2. API KEY inválida\n";
    echo "3. Firewall o red bloqueando conexión\n";
    echo "4. Servicio EFEVOOPAY caído\n";
}

echo "\n==================================================\n";
echo "RESUMEN Y RECOMENDACIONES\n";
echo "==================================================\n";

echo "\n📋 PASOS SIGUIENTES:\n";
echo "1. Verificar que las transacciones aparezcan en el panel de EFEVOOPAY\n";
echo "2. Confirmar con el banco si hay movimientos reales\n";
echo "3. Documentar los códigos de respuesta específicos del entorno productivo\n";
echo "4. Configurar webhooks para notificaciones automáticas\n\n";

echo "🔍 PARA DEBUG AVANZADO:\n";
echo "1. Activar logging detallado en el script\n";
echo "2. Usar herramientas como Postman para pruebas manuales\n";
echo "3. Monitorear logs del servidor\n";
echo "4. Verificar certificados SSL\n\n";

echo "📞 SOPORTE EFEVOOPAY:\n";
echo "1. Proporcionar ID de transacciones para consulta\n";
echo "2. Confirmar montos de prueba permitidos\n";
echo "3. Verificar configuración de cuenta productiva\n";

echo "\n🎯 ARCHIVOS GENERADOS:\n";
if (file_exists('token_cliente_productivo.txt')) {
    echo "- token_cliente_productivo.txt\n";
}
if (file_exists('token_tarjeta_productivo.txt')) {
    echo "- token_tarjeta_productivo.txt\n";
}
foreach (glob('respuesta_tokenizacion_*.json') as $file) {
    echo "- $file\n";
}
foreach (glob('transacciones_*.json') as $file) {
    echo "- $file\n";
}

echo "\n==================================================\n";
echo "FIN DE LA PRUEBA - " . date('Y-m-d H:i:s') . "\n";
echo "==================================================\n";