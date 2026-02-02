<?php
// test-tokenizacion-fixed-token.php

// ============================================
// CONFIGURACIÓN CON TOKEN FIJO
// ============================================
$config = [
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay',
    'api_key' => 'Hq#J0hs)jK+YqF6J',
    'clave' => '6nugHedWzw27MNB8',
    'cliente' => 'TestFAMEDIC',
    'vector' => 'MszjlcnTjGLNpNy3',
    'fixed_token' => 'Q2VzcEwzZEtHRnN6VnpGTXdNdWFCVHYwa0VsN2RSSEN5YlZJMEpUVU5DVT0='
];

// ============================================
// FUNCIONES
// ============================================

function makeRequest($url, $headers, $body, $timeout = 15) {
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
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $end = microtime(true);
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'code' => $httpCode, 
        'body' => $response,
        'error' => $error,
        'time' => round(($end - $start), 2)
    ];
}

// ============================================
// PRUEBA CON TOKEN FIJO
// ============================================

echo "========================================\n";
echo "TOKENIZACIÓN CON TOKEN FIJO (1 AÑO)\n";
echo "========================================\n\n";

echo "📋 CONFIGURACIÓN:\n";
echo "   Token fijo: " . substr($config['fixed_token'], 0, 30) . "...\n";
echo "   Cliente: {$config['cliente']}\n";
echo "   Clave: " . substr($config['clave'], 0, 6) . "...\n";
echo "   Vector: " . substr($config['vector'], 0, 6) . "...\n\n";

$headers = [
    'Content-Type: application/json',
    'X-API-USER: ' . $config['api_user'],
    'X-API-KEY: ' . $config['api_key']
];

// 1. Verificar que el token fijo funcione
echo "1. VERIFICANDO TOKEN FIJO:\n";
echo "   Enviando... ";

$bodyVerificacion = json_encode([
    'method' => 'validateToken',
    'token' => $config['fixed_token']
]);

$result = makeRequest($config['api_url'], $headers, $bodyVerificacion, 10);

if ($result['code'] == 200) {
    $response = json_decode($result['body'], true);
    echo "✅ HTTP {$result['code']}\n";
    echo "   Código: " . ($response['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($response['msg'] ?? $response['mensaje'] ?? 'Sin mensaje') . "\n";
    
    if (($response['codigo'] ?? '') == '00') {
        echo "   ✅ Token válido\n";
    } else {
        echo "   ❌ Token inválido o expirado\n";
        exit;
    }
} else {
    echo "❌ ERROR HTTP: {$result['code']}\n";
    echo "   Error: {$result['error']}\n";
    exit;
}

// 2. Probar tokenización con tarjeta de prueba
echo "\n2. PROBANDO TOKENIZACIÓN:\n";

// Tarjeta de prueba y monto mínimo
$tarjeta = '5267772159330969';
$expiracion = '3111'; // MMYY - Noviembre 2031
$montoMinimo = '0.01'; // Mínimo absoluto

echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
echo "   Expiración: $expiracion (MMYY)\n";
echo "   Monto: \${$montoMinimo} MXN\n\n";

echo "   ¿Continuar? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) != 's') {
    echo "   Cancelado por usuario\n";
    exit;
}

// 3. Preparar datos para encriptar
$datosParaEncriptar = [
    'track2' => $tarjeta . '=' . $expiracion,
    'amount' => $montoMinimo
];

echo "   Datos a encriptar: " . json_encode($datosParaEncriptar) . "\n";

// 4. Encriptar datos (AES-128-CBC)
$plaintext = json_encode($datosParaEncriptar, JSON_UNESCAPED_UNICODE);
$encrypted = base64_encode(openssl_encrypt(
    $plaintext,
    'AES-128-CBC',
    $config['clave'],
    OPENSSL_RAW_DATA,
    $config['vector']
));

echo "   Datos encriptados (" . strlen($encrypted) . " chars): " . substr($encrypted, 0, 60) . "...\n";

// 5. Enviar solicitud de tokenización
echo "\n3. ENVIANDO SOLICITUD DE TOKENIZACIÓN:\n";

$bodyTokenizacion = json_encode([
    'method' => 'getTokenize',
    'token' => $config['fixed_token'],
    'encrypt' => $encrypted
]);

echo "   Enviando... ";
$resultToken = makeRequest($config['api_url'], $headers, $bodyTokenizacion, 15);

if ($resultToken['code'] == 200) {
    $responseToken = json_decode($resultToken['body'], true);
    echo "✅ RESPUESTA RECIBIDA\n\n";
    
    echo "📊 RESULTADO:\n";
    echo "   Código: " . ($responseToken['codigo'] ?? 'N/A') . "\n";
    echo "   Mensaje: " . ($responseToken['msg'] ?? $responseToken['mensaje'] ?? 'Sin mensaje') . "\n";
    
    if (isset($responseToken['descripcion'])) {
        echo "   Descripción: " . $responseToken['descripcion'] . "\n";
    }
    
    // Interpretar códigos
    $codigo = $responseToken['codigo'] ?? '';
    
    switch($codigo) {
        case '00':
            echo "\n   🎉 ¡APROBADO! Tokenización exitosa\n";
            if (isset($responseToken['token'])) {
                echo "   Token de tarjeta: " . $responseToken['token'] . "\n";
                file_put_contents('token_tarjeta_final.txt', $responseToken['token']);
                echo "   📝 Token guardado en 'token_tarjeta_final.txt'\n";
            }
            break;
            
        case '102':
            echo "\n   ❌ LLAVES INCORRECTAS\n";
            echo "   El token fijo no es válido o expiró\n";
            break;
            
        case '30':
            echo "\n   ❌ ERROR DE FORMATO\n";
            echo "   Revisa el formato de los datos encriptados\n";
            break;
            
        case '05':
            echo "\n   ❌ NO HONRAR - Tarjeta rechazada\n";
            echo "   El banco no aprobó la transacción\n";
            break;
            
        case '51':
            echo "\n   ❌ FONDOS INSUFICIENTES\n";
            echo "   La tarjeta no tiene fondos suficientes\n";
            break;
            
        case '14':
            echo "\n   ❌ NÚMERO DE TARJETA INVÁLIDO\n";
            echo "   Revisa el número de tarjeta\n";
            break;
            
        default:
            echo "\n   ⚠ Código no reconocido: $codigo\n";
    }
    
    // Mostrar respuesta completa para debugging
    echo "\n📄 RESPUESTA COMPLETA:\n";
    print_r($responseToken);
    
} else {
    echo "❌ ERROR HTTP: {$resultToken['code']}\n";
    echo "   Error: {$resultToken['error']}\n";
    if ($resultToken['body']) {
        echo "   Respuesta: {$resultToken['body']}\n";
    }
}

// 6. Buscar transacciones (opcional)
echo "\n4. BUSCANDO TRANSACCIONES RECIENTES:\n";

$bodyBusqueda = json_encode([
    'method' => 'getTranSearch',
    'token' => $config['fixed_token'],
    'range1' => date('Y-m-d 00:00:00'),
    'range2' => date('Y-m-d 23:59:59')
]);

echo "   Buscando... ";
$resultBusqueda = makeRequest($config['api_url'], $headers, $bodyBusqueda, 10);

if ($resultBusqueda['code'] == 200) {
    $responseBusqueda = json_decode($resultBusqueda['body'], true);
    
    if (isset($responseBusqueda['codigo']) && $responseBusqueda['codigo'] == '00') {
        if (isset($responseBusqueda['data']) && is_array($responseBusqueda['data'])) {
            echo "✅ " . count($responseBusqueda['data']) . " transacciones encontradas\n";
            
            // Mostrar las 3 más recientes
            $count = 0;
            foreach ($responseBusqueda['data'] as $trans) {
                if ($count >= 3) break;
                
                echo "\n   📋 Transacción #" . ($count + 1) . ":\n";
                echo "   ID: " . ($trans['ID'] ?? $trans['id'] ?? 'N/A') . "\n";
                echo "   Monto: $" . ($trans['amount'] ?? 'N/A') . "\n";
                echo "   Fecha: " . ($trans['fecha'] ?? $trans['date'] ?? 'N/A') . "\n";
                echo "   Estado: " . ($trans['approved'] ?? $trans['status'] ?? 'N/A') . "\n";
                echo "   Tipo: " . ($trans['type'] ?? $trans['Transaccion'] ?? 'N/A') . "\n";
                
                $count++;
            }
        } else {
            echo "⚠ No se encontraron transacciones\n";
        }
    } else {
        echo "❌ Error en búsqueda: " . ($responseBusqueda['msg'] ?? 'Desconocido') . "\n";
    }
} else {
    echo "❌ Error HTTP: {$resultBusqueda['code']}\n";
}

echo "\n========================================\n";
echo "NOTAS IMPORTANTES:\n";
echo "========================================\n";
echo "1. Este token fijo tiene vigencia de 1 AÑO\n";
echo "2. Usa siempre montos mínimos (¢0.01) en pruebas\n";
echo "3. Verifica que la tarjeta sea de PRUEBAS\n";
echo "4. Monitorea tu estado de cuenta por cargos inesperados\n";
echo "5. Guarda el token de tarjeta para futuras transacciones\n";