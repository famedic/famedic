<?php
// 
// test-tokenizacion-y-pagos-final.php
/*
llaves de productivo 

9e21f21d434ba4ab219a3cd3ad6c3171c142ece4ff87b0f12b4035106b22e162
*/
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

function saveToLog($filename, $data) {
    $timestamp = date('Y-m-d H:i:s');
    $logData = "========== [$timestamp] ==========\n";
    $logData .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    file_put_contents($filename, $logData, FILE_APPEND);
}

// ============================================
// PRUEBA COMPLETA: TOKENIZACIÓN + PAGO + DEVOLUCIÓN
// ============================================

echo "========================================\n";
echo "PRUEBA COMPLETA: TOKENIZACIÓN Y PAGOS\n";
echo "========================================\n\n";

// Obtener token de cliente
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

echo "✅ ÉXITO - Token Cliente: " . substr($tokenCliente, 0, 20) . "...\n";

// Preguntar si usar token existente o tokenizar nueva tarjeta
echo "\n¿Qué deseas hacer?\n";
echo "1. Usar token existente (de token_tarjeta_final.txt)\n";
echo "2. Tokenizar nueva tarjeta\n";
echo "Selecciona (1/2): ";

$handle = fopen("php://stdin", "r");
$option = trim(fgets($handle));

if ($option == '1' && file_exists('token_tarjeta_final.txt')) {
    $tokenTarjeta = trim(file_get_contents('token_tarjeta_final.txt'));
    echo "\n✓ Usando token existente: " . $tokenTarjeta . "\n";
} else {
    // Tokenizar nueva tarjeta
    echo "\n2. TOKENIZACIÓN DE TARJETA\n";
    
    // Usar tarjeta de prueba
    $tarjeta = '5267772159330969';
    $expiracion = '3111'; // MMYY
    $montoTokenizacion = '0.01'; // Mínimo para tokenizar
    
    echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
    echo "   Expiración: $expiracion (MMYY)\n";
    echo "   Monto tokenización: $$montoTokenizacion MXN\n\n";
    
    echo "   ¿Continuar? (s/n): ";
    $confirm = trim(fgets($handle));
    
    if (strtolower($confirm) != 's') {
        echo "   Cancelado por usuario\n";
        exit;
    }
    
    $datosTokenizacion = [
        'track2' => $tarjeta . '=' . $expiracion,
        'amount' => $montoTokenizacion
    ];
    
    echo "   Encriptando datos... ";
    $encryptedToken = encryptDataAES($datosTokenizacion, $config['clave'], $config['vector']);
    echo "✅\n";
    
    $bodyTokenizacion = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encryptedToken
        ],
        'method' => 'getTokenize'
    ]);
    
    echo "   Enviando solicitud de tokenización... ";
    $resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion);
    
    if ($resultToken['code'] == 200) {
        $responseToken = json_decode($resultToken['body'], true);
        
        if (($responseToken['codigo'] ?? '') == '00' && isset($responseToken['token'])) {
            $tokenTarjeta = $responseToken['token'];
            echo "✅ TOKEN OBTENIDO\n";
            echo "   Token: " . $tokenTarjeta . "\n";
            
            // Guardar token para uso futuro
            file_put_contents('token_tarjeta_final.txt', $tokenTarjeta);
            echo "   📝 Token guardado en 'token_tarjeta_final.txt'\n";
            
            saveToLog('log_tokenizacion.txt', [
                'fecha' => date('Y-m-d H:i:s'),
                'token_tarjeta' => $tokenTarjeta,
                'respuesta' => $responseToken
            ]);
        } else {
            echo "❌ Error en tokenización\n";
            echo "   Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
            echo "   Mensaje: " . ($responseToken['mensaje'] ?? $responseToken['msg'] ?? 'Sin mensaje') . "\n";
            exit;
        }
    } else {
        echo "❌ ERROR HTTP: " . $resultToken['code'] . "\n";
        exit;
    }
}

fclose($handle);

// ============================================
// 3. REALIZAR PAGO CON TOKEN
// ============================================

echo "\n3. REALIZAR PAGO CON TOKEN\n";

// Generar CAV único (Transaction ID)
$cav = 'PAY' . date('YmdHis') . rand(100, 999);

$datosPago = [
    'track2' => $tokenTarjeta, // ¡IMPORTANTE! Usar el token en lugar del track2
    'amount' => '5.00', // Monto de $5.00 MXN
    'cvv' => '', // Vacío cuando se usa token
    'cav' => $cav,
    'msi' => 0, // Sin meses sin intereses
    'contrato' => '', // Vacío si no es pago recurrente
    'fiid_comercio' => '', // Dejar vacío o usar el proporcionado por Efevoo
    'referencia' => 'TestFAMEDIC' // Nombre del comercio
];

echo "   Token: " . $tokenTarjeta . "\n";
echo "   Monto: $" . $datosPago['amount'] . " MXN\n";
echo "   CAV (Transaction ID): " . $datosPago['cav'] . "\n";
echo "   Referencia: " . $datosPago['referencia'] . "\n\n";

echo "   ¿Realizar pago de $5.00 MXN? (s/n): ";
$handle = fopen("php://stdin", "r");
$confirm = trim(fgets($handle));
fclose($handle);

if (strtolower($confirm) != 's') {
    echo "   Pago cancelado\n";
    exit;
}

echo "   Encriptando datos de pago... ";
$encryptedPago = encryptDataAES($datosPago, $config['clave'], $config['vector']);
echo "✅\n";

$bodyPago = json_encode([
    'payload' => [
        'token' => $tokenCliente,
        'encrypt' => $encryptedPago
    ],
    'method' => 'getPayment'
]);

echo "   Enviando solicitud de pago... ";
$resultPago = makeRequest($config['api_url'], $headers, $bodyPago);

$idTransaccion = null;
$authCode = null;

if ($resultPago['code'] == 200) {
    $responsePago = json_decode($resultPago['body'], true);
    
    echo "✅ RESPUESTA RECIBIDA\n\n";
    
    // Guardar respuesta completa
    saveToLog('log_pagos.txt', [
        'tipo' => 'PAGO',
        'fecha' => date('Y-m-d H:i:s'),
        'cav' => $cav,
        'token_tarjeta' => $tokenTarjeta,
        'monto' => '5.00',
        'respuesta_completa' => $responsePago
    ]);
    
    echo "📊 RESULTADO DEL PAGO:\n";
    echo "   Código: " . ($responsePago['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($responsePago['mensaje'] ?? $responsePago['msg'] ?? 'Sin mensaje') . "\n";
    
    // Extraer información importante
    if (isset($responsePago['id'])) {
        $idTransaccion = $responsePago['id'];
        echo "   ID Transacción: " . $idTransaccion . "\n";
    }
    
    if (isset($responsePago['auth'])) {
        $authCode = $responsePago['auth'];
        echo "   Código Autorización: " . $authCode . "\n";
    }
    
    if (isset($responsePago['descripcion'])) {
        echo "   Descripción: " . $responsePago['descripcion'] . "\n";
    }
    
    if (isset($responsePago['reference'])) {
        echo "   Referencia: " . $responsePago['reference'] . "\n";
    }
    
    // Interpretar códigos
    $codigo = $responsePago['codigo'] ?? '';
    
    switch($codigo) {
        case '00':
            echo "\n   🎉 ¡PAGO APROBADO!\n";
            echo "   Guarda el ID de transacción para la devolución\n";
            break;
            
        case '05':
            echo "\n   ❌ NO HONRAR - Tarjeta rechazada\n";
            echo "   El banco no aprobó la transacción\n";
            break;
            
        case '30':
            echo "\n   ❌ ERROR DE FORMATO\n";
            echo "   Revisa el formato de los datos enviados\n";
            break;
            
        case '14':
            echo "\n   ❌ TARJETA INVÁLIDA\n";
            echo "   El token de tarjeta no es válido\n";
            break;
            
        default:
            echo "\n   ⚠ Código no reconocido: $codigo\n";
    }
    
    // Mostrar respuesta completa en modo debug
    echo "\n📄 RESPUESTA COMPLETA DEL PAGO:\n";
    print_r($responsePago);
    
} else {
    echo "❌ ERROR HTTP en pago: " . $resultPago['code'] . "\n";
    echo "   Respuesta: " . $resultPago['body'] . "\n";
    exit;
}

// Si el pago fue aprobado, proceder con devolución
if (($responsePago['codigo'] ?? '') == '00' && $idTransaccion) {
    
    echo "\n4. SOLICITAR DEVOLUCIÓN (REFUND)\n";
    echo "   ID Transacción: " . $idTransaccion . "\n";
    echo "   Monto a devolver: $5.00 MXN\n\n";
    
    echo "   ¿Realizar devolución completa? (s/n): ";
    $handle = fopen("php://stdin", "r");
    $confirmRefund = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($confirmRefund) == 's') {
        // Preparar datos para devolución
        $datosDevolucion = [
            'track2' => $tokenTarjeta,
            'amount' => '5.00', // Monto completo a devolver
            'cvv' => '',
            'cav' => 'REF' . date('YmdHis') . rand(100, 999), // Nuevo CAV para la devolución
            'msi' => 0,
            'contrato' => '',
            'fiid_comercio' => '',
            'referencia' => 'Devolucion TestFAMEDIC',
            'transaction_id' => $idTransaccion // ¡IMPORTANTE! ID de la transacción original
        ];
        
        echo "   Encriptando datos de devolución... ";
        $encryptedRefund = encryptDataAES($datosDevolucion, $config['clave'], $config['vector']);
        echo "✅\n";
        
        $bodyRefund = json_encode([
            'payload' => [
                'token' => $tokenCliente,
                'encrypt' => $encryptedRefund
            ],
            'method' => 'getRefund' // Método para devolución
        ]);
        
        echo "   Enviando solicitud de devolución... ";
        $resultRefund = makeRequest($config['api_url'], $headers, $bodyRefund);
        
        if ($resultRefund['code'] == 200) {
            $responseRefund = json_decode($resultRefund['body'], true);
            
            echo "✅ RESPUESTA RECIBIDA\n\n";
            
            // Guardar respuesta de devolución
            saveToLog('log_pagos.txt', [
                'tipo' => 'DEVOLUCIÓN',
                'fecha' => date('Y-m-d H:i:s'),
                'id_transaccion_original' => $idTransaccion,
                'cav_devolucion' => $datosDevolucion['cav'],
                'respuesta_completa' => $responseRefund
            ]);
            
            echo "📊 RESULTADO DE LA DEVOLUCIÓN:\n";
            echo "   Código: " . ($responseRefund['codigo'] ?? 'N/A') . "\n";
            echo "   Mensaje: " . ($responseRefund['mensaje'] ?? $responseRefund['msg'] ?? 'Sin mensaje') . "\n";
            
            if (isset($responseRefund['id'])) {
                echo "   ID Devolución: " . $responseRefund['id'] . "\n";
            }
            
            if (isset($responseRefund['descripcion'])) {
                echo "   Descripción: " . $responseRefund['descripcion'] . "\n";
            }
            
            // Interpretar códigos de devolución
            $codigoRefund = $responseRefund['codigo'] ?? '';
            
            switch($codigoRefund) {
                case '00':
                    echo "\n   ✅ ¡DEVOLUCIÓN EXITOSA!\n";
                    echo "   El monto ha sido devuelto a la tarjeta\n";
                    break;
                    
                case '05':
                    echo "\n   ❌ DEVOLUCIÓN RECHAZADA\n";
                    echo "   No se pudo procesar la devolución\n";
                    break;
                    
                case '54':
                    echo "\n   ⚠ TRANSACCIÓN NO ENCONTRADA\n";
                    echo "   Verifica el ID de transacción\n";
                    break;
                    
                default:
                    echo "\n   ⚠ Código no reconocido: $codigoRefund\n";
            }
            
            echo "\n📄 RESPUESTA COMPLETA DE LA DEVOLUCIÓN:\n";
            print_r($responseRefund);
            
        } else {
            echo "❌ ERROR HTTP en devolución: " . $resultRefund['code'] . "\n";
            echo "   Respuesta: " . $resultRefund['body'] . "\n";
        }
    } else {
        echo "   Devolución cancelada por usuario\n";
    }
} else {
    echo "\n⚠ No se puede realizar devolución - El pago no fue aprobado\n";
}

// ============================================
// 5. BUSCAR TRANSACCIONES
// ============================================

echo "\n5. BUSCAR TRANSACCIONES RECIENTES\n";

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
        $transacciones = $responseBusqueda['data'];
        echo "✅ " . count($transacciones) . " transacciones encontradas\n";
        
        // Filtrar transacciones relevantes
        $transaccionesRelevantes = [];
        foreach ($transacciones as $trans) {
            if (($trans['amount'] ?? 0) == '5.00' || 
                ($trans['amount'] ?? 0) == '0.01' ||
                ($trans['ID'] ?? '') == $idTransaccion) {
                $transaccionesRelevantes[] = $trans;
            }
        }
        
        if (count($transaccionesRelevantes) > 0) {
            echo "\n📋 TRANSACCIONES RELEVANTES ENCONTRADAS:\n";
            foreach ($transaccionesRelevantes as $index => $trans) {
                echo "   [" . ($index + 1) . "] ---------------------------------\n";
                echo "   ID: " . ($trans['ID'] ?? $trans['id'] ?? 'N/A') . "\n";
                echo "   Monto: $" . ($trans['amount'] ?? 'N/A') . "\n";
                echo "   Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                echo "   Estado: " . ($trans['approved'] ?? $trans['status'] ?? 'N/A') . "\n";
                echo "   Tipo: " . ($trans['type'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                echo "   Referencia: " . ($trans['reference'] ?? 'N/A') . "\n";
                if (isset($trans['auth'])) {
                    echo "   Auth: " . $trans['auth'] . "\n";
                }
                echo "\n";
            }
        } else {
            echo "⚠ No se encontraron transacciones relevantes\n";
        }
    } else {
        echo "⚠ No se encontraron transacciones\n";
    }
} else {
    echo "❌ Error al buscar transacciones\n";
}

echo "\n========================================\n";
echo "RESUMEN DE LA PRUEBA\n";
echo "========================================\n";

echo "✅ Token Cliente: " . substr($tokenCliente, 0, 20) . "...\n";
echo "✅ Token Tarjeta: " . $tokenTarjeta . "\n";

if ($idTransaccion) {
    echo "✅ ID Transacción Pago: " . $idTransaccion . "\n";
    echo "✅ Monto Pagado: $5.00 MXN\n";
    
    if (isset($responseRefund) && ($responseRefund['codigo'] ?? '') == '00') {
        echo "✅ Devolución Exitosa\n";
        echo "   ID Devolución: " . ($responseRefund['id'] ?? 'N/A') . "\n";
    } else {
        echo "⚠ Devolución no realizada\n";
    }
} else {
    echo "❌ Pago no aprobado\n";
}

echo "\n📁 LOGS GUARDADOS:\n";
echo "   - token_tarjeta_final.txt (token de tarjeta)\n";
echo "   - log_tokenizacion.txt (detalles de tokenización)\n";
echo "   - log_pagos.txt (detalles de pagos y devoluciones)\n";

echo "\n⚠ RECOMENDACIONES:\n";
echo "1. Verifica en tu cuenta bancaria si hubo cargos reales\n";
echo "2. Para producción, usa montos mínimos ($0.01) en pruebas\n";
echo "3. Guarda siempre los IDs de transacción para referencias futuras\n";
echo "4. Contacta a EfevooPay para confirmar comportamiento en ambiente test\n";