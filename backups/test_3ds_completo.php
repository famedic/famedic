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
    'vector' => 'MszjlcnTjGLNpNy3',
    'fiid_comercio' => '1827' // Agregado para 3DS
];

// ============================================
// FUNCIONES
// ============================================

function generateTOTP($secret) {
    echo "🔵 Generando TOTP...\n";
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
    echo "✅ TOTP generado: $totp\n";
    return $totp;
}

function generateHash($totp, $clave) {
    echo "🔵 Generando hash con TOTP...\n";
    $hash = base64_encode(hash_hmac('sha256', $clave, $totp, true));
    echo "✅ Hash generado: " . substr($hash, 0, 30) . "...\n";
    return $hash;
}

function encryptDataAES($data, $clave, $vector) {
    echo "🔵 Encriptando datos AES...\n";
    echo "   Tipo de dato: " . gettype($data) . "\n";
    
    if (is_array($data)) {
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
        echo "   JSON creado, longitud: " . strlen($plaintext) . "\n";
    } else {
        $plaintext = $data;
    }
    
    $encrypted = openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $clave,
        OPENSSL_RAW_DATA,
        $vector
    );
    
    if ($encrypted === false) {
        echo "❌ Error en encriptación: " . openssl_error_string() . "\n";
        return false;
    }
    
    $base64 = base64_encode($encrypted);
    echo "✅ Datos encriptados, longitud base64: " . strlen($base64) . "\n";
    echo "   Previsualización: " . substr($base64, 0, 50) . "...\n";
    return $base64;
}

function makeRequest($url, $headers, $body) {
    echo "🔵 Enviando request a: $url\n";
    echo "   Headers: " . json_encode($headers) . "\n";
    echo "   Body length: " . strlen($body) . "\n";
    echo "   Body preview: " . substr($body, 0, 200) . "...\n";
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => true // Para debugging
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    echo "✅ Response HTTP Code: $httpCode\n";
    echo "   Response length: " . strlen($response) . "\n";
    echo "   Response preview: " . substr($response, 0, 500) . "...\n";
    
    if ($error) {
        echo "❌ cURL Error: $error\n";
    }
    
    return ['code' => $httpCode, 'body' => $response, 'error' => $error];
}

function getClientToken($config) {
    echo "\n🔵 ========== OBTENIENDO TOKEN CLIENTE ==========\n";
    
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
        echo "❌ ERROR HTTP: " . $result['code'] . "\n";
        return null;
    }

    $response = json_decode($result['body'], true);
    $tokenCliente = $response['token'] ?? null;

    if (!$tokenCliente) {
        echo "❌ No se obtuvo token\n";
        echo "   Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
        return null;
    }

    echo "✅ Token obtenido: " . substr($tokenCliente, 0, 50) . "...\n";
    return $tokenCliente;
}

function testTokenization($config, $tokenCliente) {
    echo "\n🔵 ========== PROBANDO TOKENIZACIÓN NORMAL ==========\n";
    
    $tarjeta = '5267772159330969';
    $expiracion = '3111'; // MMYY
    $montoMinimo = '1.50';

    echo "   Tarjeta: " . substr($tarjeta, 0, 6) . "****" . substr($tarjeta, -4) . "\n";
    echo "   Expiración: $expiracion (MMYY)\n";
    echo "   Monto: $$montoMinimo MXN\n";

    $datos = [
        'track2' => $tarjeta . '=' . $expiracion,
        'amount' => $montoMinimo
    ];

    $encrypted = encryptDataAES($datos, $config['clave'], $config['vector']);
    
    if (!$encrypted) {
        echo "❌ Error en encriptación, abortando...\n";
        return false;
    }

    $bodyTokenizacion = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'getTokenize'
    ]);

    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];

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
        
        if (isset($responseToken['token'])) {
            echo "   Token obtenido: " . $responseToken['token'] . "\n";
            file_put_contents('token_tarjeta_normal.txt', $responseToken['token']);
            echo "   📝 Token guardado en 'token_tarjeta_normal.txt'\n";
        }
        
        return $responseToken;
    } else {
        echo "❌ ERROR HTTP: " . $resultToken['code'] . "\n";
        echo "   Respuesta: " . $resultToken['body'] . "\n";
        return false;
    }
}

function test3DSGetLink($config, $tokenCliente) {
    echo "\n🔵 ========== PROBANDO 3DS GETLINK ==========\n";
    
    // Datos EXACTOS según documentación
    $testData = [
        'track' => '5123000011112222',
        'cvv' => '111',
        'exp' => '11/11', // Formato MM/YY
        'fiid_comercio' => $config['fiid_comercio'],
        'msi' => 0,
        'amount' => '1.00',
        'browser' => [
            'browserAcceptHeader' => 'application/json',
            'browserJavaEnabled' => false,
            'browserJavaScriptEnabled' => true,
            'browserLanguage' => 'es-419',
            'browserTZ' => '360', // Sin signo, como en doc
            'browserUserAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
        ]
    ];

    echo "📝 Datos para 3DS:\n";
    echo "   Tarjeta: " . substr($testData['track'], 0, 6) . "****" . substr($testData['track'], -4) . "\n";
    echo "   Expiración: " . $testData['exp'] . " (MM/YY)\n";
    echo "   CVV: " . $testData['cvv'] . "\n";
    echo "   Monto: $" . $testData['amount'] . "\n";
    echo "   fiid_comercio: " . $testData['fiid_comercio'] . "\n";
    echo "   browserTZ: " . $testData['browser']['browserTZ'] . "\n";

    $encrypted = encryptDataAES($testData, $config['clave'], $config['vector']);
    
    if (!$encrypted) {
        echo "❌ Error en encriptación 3DS\n";
        return false;
    }

    $payload = [
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'payments3DS_GetLink'
    ];

    echo "\n📦 Payload para 3DS:\n";
    echo "   Método: " . $payload['method'] . "\n";
    echo "   Token: " . substr($payload['payload']['token'], 0, 30) . "...\n";
    echo "   Encrypted length: " . strlen($payload['payload']['encrypt']) . "\n";

    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];

    $result = makeRequest($config['api_url'], $headers, json_encode($payload));

    echo "\n📊 RESPUESTA 3DS GETLINK:\n";
    echo "   HTTP Code: " . $result['code'] . "\n";
    
    if ($result['code'] == 200) {
        $response = json_decode($result['body'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Error decodificando JSON: " . json_last_error_msg() . "\n";
            echo "   Raw response: " . $result['body'] . "\n";
            return false;
        }
        
        echo "   ✅ JSON válido recibido\n";
        
        // Guardar respuesta completa
        file_put_contents('3ds_getlink_response.json', json_encode($response, JSON_PRETTY_PRINT));
        echo "   📝 Respuesta guardada en '3ds_getlink_response.json'\n";
        
        // Analizar respuesta
        echo "\n🔍 ANÁLISIS DE RESPUESTA:\n";
        
        if (isset($response['url_3dsecure']) && isset($response['token_3dsecure'])) {
            echo "   🎉 3DS REQUERIDO - URL y Token recibidos\n";
            echo "   URL: " . substr($response['url_3dsecure'], 0, 100) . "...\n";
            echo "   Token: " . substr($response['token_3dsecure'], 0, 50) . "...\n";
            echo "   Order ID: " . ($response['order_id'] ?? 'N/A') . "\n";
            
            // Generar HTML para iframe
            generate3DSIframe($response['url_3dsecure'], $response['token_3dsecure']);
            
        } elseif (empty($response['url_3dsecure']) && empty($response['token_3dsecure'])) {
            echo "   ℹ️ NO REQUIERE 3DS - Proceder con GetStatus\n";
            echo "   Order ID: " . ($response['order_id'] ?? 'N/A') . "\n";
            
            if (isset($response['order_id'])) {
                test3DSGetStatus($config, $tokenCliente, $response['order_id'], $testData);
            } else {
                echo "   ❌ No hay order_id para GetStatus\n";
            }
            
        } else {
            echo "   ⚠️ RESPUESTA PARCIAL\n";
            echo "   URL presente: " . (isset($response['url_3dsecure']) ? 'Sí' : 'No') . "\n";
            echo "   Token presente: " . (isset($response['token_3dsecure']) ? 'Sí' : 'No') . "\n";
        }
        
        // Mostrar toda la respuesta
        echo "\n📄 RESPUESTA COMPLETA:\n";
        print_r($response);
        
        return $response;
        
    } elseif ($result['code'] == 404) {
        echo "❌ ERROR 404 - MÉTODO NO ENCONTRADO\n";
        echo "   El método 'payments3DS_GetLink' no está disponible\n";
        echo "   Contacta a EfevooPay para habilitarlo\n";
        return false;
        
    } elseif ($result['code'] == 500) {
        echo "❌ ERROR 500 - ERROR DE SERVIDOR\n";
        echo "   Posible error en el cifrado o formato de datos\n";
        echo "   Revisa los datos y el cifrado\n";
        return false;
        
    } else {
        echo "❌ ERROR HTTP: " . $result['code'] . "\n";
        echo "   Respuesta: " . $result['body'] . "\n";
        return false;
    }
}

function test3DSGetStatus($config, $tokenCliente, $orderId, $cardData) {
    echo "\n🔵 ========== PROBANDO 3DS GETSTATUS ==========\n";
    
    if (!$orderId) {
        echo "❌ No hay order_id para GetStatus\n";
        return false;
    }
    
    echo "   Order ID: $orderId\n";
    
    $statusData = [
        'track' => $cardData['track'],
        'cvv' => $cardData['cvv'],
        'exp' => $cardData['exp'], // Mantener formato MM/YY
        'order_id' => (int)$orderId
    ];
    
    echo "📝 Datos para GetStatus:\n";
    echo "   Order ID: " . $statusData['order_id'] . "\n";
    echo "   Expiración: " . $statusData['exp'] . "\n";

    $encrypted = encryptDataAES($statusData, $config['clave'], $config['vector']);
    
    if (!$encrypted) {
        echo "❌ Error en encriptación GetStatus\n";
        return false;
    }

    $payload = [
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'payments3DS_GetStatus'
    ];

    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];

    $result = makeRequest($config['api_url'], $headers, json_encode($payload));

    echo "\n📊 RESPUESTA 3DS GETSTATUS:\n";
    echo "   HTTP Code: " . $result['code'] . "\n";
    
    if ($result['code'] == 200) {
        $response = json_decode($result['body'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Error decodificando JSON: " . json_last_error_msg() . "\n";
            return false;
        }
        
        echo "   ✅ JSON válido recibido\n";
        
        // Guardar respuesta
        file_put_contents('3ds_getstatus_response.json', json_encode($response, JSON_PRETTY_PRINT));
        echo "   📝 Respuesta guardada en '3ds_getstatus_response.json'\n";
        
        // Analizar respuesta
        echo "\n🔍 ANÁLISIS GETSTATUS:\n";
        
        if (isset($response['codigo']) && $response['codigo'] == '00') {
            echo "   🎉 APROBADO - Código: " . $response['codigo'] . "\n";
            echo "   Mensaje: " . ($response['descripcion'] ?? $response['mensaje'] ?? 'Sin mensaje') . "\n";
            
            // Aquí procederíamos con tokenización normal
            echo "   Proceder con tokenización normal...\n";
            
        } else {
            echo "   ❌ NO APROBADO\n";
            echo "   Código: " . ($response['codigo'] ?? 'N/A') . "\n";
            echo "   Mensaje: " . ($response['descripcion'] ?? $response['mensaje'] ?? $response['error'] ?? 'Sin mensaje') . "\n";
        }
        
        echo "\n📄 RESPUESTA COMPLETA:\n";
        print_r($response);
        
        return $response;
        
    } else {
        echo "❌ ERROR HTTP: " . $result['code'] . "\n";
        echo "   Respuesta: " . $result['body'] . "\n";
        return false;
    }
}

function generate3DSIframe($url, $token) {
    echo "\n🔵 ========== GENERANDO IFRAME 3DS ==========\n";
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>3DS Authentication - EfevooPay</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .info-box { background: #f0f8ff; border: 1px solid #87ceeb; padding: 15px; margin: 20px 0; border-radius: 5px; }
        iframe { width: 100%; height: 500px; border: 1px solid #ccc; border-radius: 5px; }
        .url-info { background: #f9f9f9; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 Autenticación 3D Secure</h1>
        
        <div class="info-box">
            <h3>Información importante:</h3>
            <ul>
                <li>Serás redirigido a la página de seguridad de tu banco</li>
                <li>Ingresa el código que recibas por SMS o en tu app bancaria</li>
                <li>Este proceso es obligatorio por regulaciones de seguridad</li>
                <li>Solo necesitas hacerlo una vez por tarjeta</li>
            </ul>
        </div>
        
        <div class="url-info">
            <strong>URL:</strong> {$url}<br>
            <strong>Token (preview):</strong> " . substr($token, 0, 50) . "..."
        </div>
        
        <iframe srcdoc='
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>3DS Authentication</title>
            </head>
            <body style="margin: 0; padding: 0;">
                <form id="3ds-form" action="{$url}" method="POST">
                    <input type="hidden" id="creq" name="creq" value="{$token}" />
                </form>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const form = document.getElementById("3ds-form");
                        console.log("Enviando formulario 3DS...");
                        form.submit();
                    });
                </script>
            </body>
            </html>
        '>
        </iframe>
        
        <div style="margin-top: 20px; color: #666; font-size: 14px;">
            <p>Si el iframe no carga, verifica que no haya bloqueadores de pop-ups activados.</p>
            <p>ID de sesión: " . time() . "</p>
        </div>
    </div>
</body>
</html>
HTML;

    file_put_contents('3ds_iframe.html', $html);
    echo "✅ Iframe generado y guardado en '3ds_iframe.html'\n";
    echo "   Abre el archivo en tu navegador para probar el 3DS\n";
    echo "   URL local: file://" . realpath('3ds_iframe.html') . "\n";
    
    return $html;
}

function testEncryptionVerification($config) {
    echo "\n🔵 ========== VERIFICACIÓN DE CIFRADO ==========\n";
    
    $testData = [
        'track' => '5123000011112222',
        'cvv' => '111',
        'exp' => '11/11',
        'fiid_comercio' => '1827',
        'msi' => 0,
        'amount' => '1.00'
    ];
    
    echo "📝 Datos de prueba:\n";
    print_r($testData);
    
    // 1. Convertir a JSON
    $json = json_encode($testData, JSON_UNESCAPED_UNICODE);
    echo "✅ JSON creado, longitud: " . strlen($json) . "\n";
    echo "   JSON: " . $json . "\n";
    
    // 2. Cifrar
    echo "🔵 Cifrando con AES-128-CBC...\n";
    echo "   Clave: " . substr($config['clave'], 0, 10) . "... (longitud: " . strlen($config['clave']) . ")\n";
    echo "   Vector: " . substr($config['vector'], 0, 10) . "... (longitud: " . strlen($config['vector']) . ")\n";
    
    $encrypted = openssl_encrypt(
        $json,
        'AES-128-CBC',
        $config['clave'],
        OPENSSL_RAW_DATA,
        $config['vector']
    );
    
    if ($encrypted === false) {
        echo "❌ Error en cifrado: " . openssl_error_string() . "\n";
        return false;
    }
    
    echo "✅ Cifrado exitoso\n";
    echo "   Longitud cifrada: " . strlen($encrypted) . "\n";
    
    // 3. Convertir a base64
    $base64 = base64_encode($encrypted);
    echo "✅ Base64 creado, longitud: " . strlen($base64) . "\n";
    echo "   Base64 (primeros 100 chars): " . substr($base64, 0, 100) . "...\n";
    
    // 4. Descifrar para verificar
    echo "🔵 Descifrando para verificación...\n";
    
    $decrypted = openssl_decrypt(
        base64_decode($base64),
        'AES-128-CBC',
        $config['clave'],
        OPENSSL_RAW_DATA,
        $config['vector']
    );
    
    if ($decrypted === false) {
        echo "❌ Error en descifrado: " . openssl_error_string() . "\n";
        return false;
    }
    
    echo "✅ Descifrado exitoso\n";
    echo "   Coincide con original: " . ($decrypted === $json ? '✅ SÍ' : '❌ NO') . "\n";
    
    if ($decrypted !== $json) {
        echo "   Original: " . $json . "\n";
        echo "   Descifrado: " . $decrypted . "\n";
        echo "   Diferencia en longitud: " . (strlen($json) - strlen($decrypted)) . "\n";
    }
    
    return $decrypted === $json;
}

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

echo "========================================\n";
echo "SCRIPT COMPLETO 3DS EFEVOOPAY\n";
echo "========================================\n\n";

echo "Este script probará:\n";
echo "1. ✅ Obtención de token cliente\n";
echo "2. ✅ Tokenización normal (para comparar)\n";
echo "3. 🔒 3DS GetLink (autenticación bancaria)\n";
echo "4. 🔍 3DS GetStatus (verificar estado)\n";
echo "5. 🔐 Verificación de cifrado\n\n";

echo "¿Continuar? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) != 's') {
    echo "Cancelado por usuario\n";
    exit;
}

// 1. Verificar cifrado primero
echo "\n🔵 ========== PASO 1: VERIFICAR CIFRADO ==========\n";
$encryptionOk = testEncryptionVerification($config);

if (!$encryptionOk) {
    echo "\n❌ ERROR EN CIFRADO - Abortando pruebas\n";
    echo "   Revisa la clave y vector en la configuración\n";
    exit;
}

echo "\n✅ CIFRADO VERIFICADO CORRECTAMENTE\n";

// 2. Obtener token de cliente
$tokenCliente = getClientToken($config);

if (!$tokenCliente) {
    echo "\n❌ NO SE PUDO OBTENER TOKEN - Abortando\n";
    exit;
}

// 3. Probar tokenización normal (para referencia)
echo "\n🔵 ========== PASO 2: TOKENIZACIÓN NORMAL (REFERENCIA) ==========\n";
echo "¿Probar tokenización normal primero? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) == 's') {
    $tokenizationResult = testTokenization($config, $tokenCliente);
    if (!$tokenizationResult) {
        echo "\n⚠️ Tokenización normal falló, pero continuaremos con 3DS\n";
    }
} else {
    echo "Saltando tokenización normal...\n";
}

// 4. Probar 3DS GetLink
echo "\n🔵 ========== PASO 3: 3DS GETLINK ==========\n";
echo "⚠️ ADVERTENCIA: Esto puede realizar un cargo real de $1.00 MXN\n";
echo "¿Continuar con 3DS? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) == 's') {
    $result3DS = test3DSGetLink($config, $tokenCliente);
    
    if ($result3DS) {
        echo "\n🎉 PRUEBA 3DS COMPLETADA\n";
        
        // Guardar resumen
        $summary = [
            'timestamp' => date('Y-m-d H:i:s'),
            'token_obtenido' => substr($tokenCliente, 0, 50) . '...',
            '3ds_result' => $result3DS,
            'files_generated' => [
                '3ds_getlink_response.json' => file_exists('3ds_getlink_response.json'),
                '3ds_getstatus_response.json' => file_exists('3ds_getstatus_response.json'),
                '3ds_iframe.html' => file_exists('3ds_iframe.html'),
                'token_tarjeta_normal.txt' => file_exists('token_tarjeta_normal.txt'),
            ]
        ];
        
        file_put_contents('test_summary.json', json_encode($summary, JSON_PRETTY_PRINT));
        echo "📝 Resumen guardado en 'test_summary.json'\n";
        
    } else {
        echo "\n❌ PRUEBA 3DS FALLÓ\n";
        echo "   Revisa los logs anteriores para identificar el problema\n";
    }
} else {
    echo "Saltando 3DS...\n";
}

// 5. Mensajes finales
echo "\n========================================\n";
echo "RESUMEN DE ARCHIVOS GENERADOS:\n";
echo "========================================\n";

$files = [
    '3ds_getlink_response.json' => 'Respuesta de GetLink 3DS',
    '3ds_getstatus_response.json' => 'Respuesta de GetStatus 3DS',
    '3ds_iframe.html' => 'Página HTML con iframe 3DS',
    'token_tarjeta_normal.txt' => 'Token de tarjeta (tokenización normal)',
    'token_tarjeta_final.txt' => 'Token de tarjeta (de tu script original)',
    'test_summary.json' => 'Resumen de pruebas'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "✅ $file - $description\n";
    } else {
        echo "❌ $file - NO GENERADO\n";
    }
}

echo "\n========================================\n";
echo "RECOMENDACIONES:\n";
echo "========================================\n";

echo "1. Si 3DS GetLink devuelve error 404:\n";
echo "   - Contacta a EfevooPay para habilitar 'payments3DS_GetLink'\n";
echo "   - Pregunta por el 'fiid_comercio' correcto\n\n";

echo "2. Si el cifrado falla:\n";
echo "   - Verifica que la clave tenga 16 caracteres\n";
echo "   - Verifica que el vector tenga 16 caracteres\n";
echo "   - Los valores deben ser EXACTAMENTE los que te dio EfevooPay\n\n";

echo "3. Para probar el iframe 3DS:\n";
echo "   - Abre '3ds_iframe.html' en tu navegador\n";
echo "   - Asegúrate de no tener bloqueadores de pop-ups\n";
echo "   - Usa una tarjeta de PRUEBA si es posible\n\n";

echo "4. Documentación importante:\n";
echo "   - Formato expiración: 'MM/YY' (ej: '11/11')\n";
echo "   - browserTZ: '360' (sin signo)\n";
echo "   - Campo tarjeta: 'track' (no 'track2')\n";
echo "   - Monto: formato '1.00' (2 decimales)\n";

echo "\n✨ Script completado ✨\n";