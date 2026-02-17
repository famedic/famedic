<?php
// test-conexion-productivo.php
// ============================================
// CONFIGURACIÓN PRODUCTIVO
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
// FUNCIONES UTILITARIAS
// ============================================

function debugCurl($url, $headers = [], $postData = null) {
    echo "🔍 DEBUG CURL REQUEST:\n";
    echo "URL: $url\n";
    echo "Headers:\n";
    foreach ($headers as $header) {
        echo "  $header\n";
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => true,
        CURLOPT_HEADER => true,
        CURLINFO_HEADER_OUT => true,
    ]);
    
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        echo "POST Data: " . substr($postData, 0, 200) . "...\n";
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Configurar verbose output
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersSent = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);
    
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    
    curl_close($ch);
    
    echo "\n📡 VERBOSE CURL OUTPUT:\n";
    echo $verboseLog;
    
    echo "\n📤 HEADERS SENT:\n";
    echo $headersSent;
    
    echo "\n📥 RESPONSE HEADERS:\n";
    echo $responseHeaders;
    
    echo "\n📄 RESPONSE BODY:\n";
    echo $responseBody;
    
    return [
        'code' => $httpCode,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'verbose' => $verboseLog
    ];
}

function testBasicConnection($config) {
    echo "========================================\n";
    echo "PRUEBA DE CONEXIÓN BÁSICA\n";
    echo "========================================\n\n";
    
    // 1. Prueba de conexión sin headers
    echo "1. PRUEBA SIN HEADERS:\n";
    $result1 = debugCurl($config['api_url']);
    echo "\nHTTP Code: " . $result1['code'] . "\n";
    
    // 2. Prueba con headers básicos
    echo "\n\n2. PRUEBA CON HEADERS BÁSICOS:\n";
    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];
    
    $testData = json_encode(['test' => 'connection']);
    $result2 = debugCurl($config['api_url'], $headers, $testData);
    echo "\nHTTP Code: " . $result2['code'] . "\n";
    
    // 3. Prueba con endpoint específico
    echo "\n\n3. PRUEBA CON ENDPOINT /status:\n";
    $statusUrl = 'https://intgapi.efevoopay.com/v1/status';
    $result3 = debugCurl($statusUrl);
    echo "\nHTTP Code: " . $result3['code'] . "\n";
    
    return [$result1, $result2, $result3];
}

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

function testCorrectAPIRequest($config) {
    echo "\n\n========================================\n";
    echo "PRUEBA DE SOLICITUD API CORRECTA\n";
    echo "========================================\n\n";
    
    // Generar TOTP y hash
    $totp = generateTOTP($config['totp_secret']);
    $hash = base64_encode(hash_hmac('sha256', $config['clave'], $totp, true));
    
    echo "[DEBUG] Hash generado: " . $hash . "\n";
    
    // Preparar la solicitud EXACTA como la documentación requiere
    $payload = [
        'method' => 'getClientToken',
        'payload' => [
            'cliente' => $config['cliente'],
            'hash' => $hash
        ]
    ];
    
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    
    echo "[DEBUG] Payload JSON:\n";
    echo $jsonPayload . "\n";
    
    // Headers específicos
    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key'],
        'Accept: application/json',
        'Cache-Control: no-cache'
    ];
    
    echo "\n[DEBUG] Headers a enviar:\n";
    print_r($headers);
    
    // Realizar la solicitud con debug completo
    $result = debugCurl($config['api_url'], $headers, $jsonPayload);
    
    echo "\n🎯 RESULTADO FINAL:\n";
    echo "HTTP Status Code: " . $result['code'] . "\n";
    
    if ($result['code'] == 200) {
        echo "✅ CONEXIÓN EXITOSA\n";
        
        // Intentar decodificar JSON
        $responseData = json_decode($result['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "\n📊 RESPUESTA JSON:\n";
            print_r($responseData);
            
            if (isset($responseData['token'])) {
                file_put_contents('token_cliente_obtenido.txt', $responseData['token']);
                echo "\n✅ Token guardado en: token_cliente_obtenido.txt\n";
            }
        } else {
            echo "⚠ Respuesta no es JSON válido\n";
            echo "Body: " . $result['body'] . "\n";
        }
    } else {
        echo "❌ ERROR DE CONEXIÓN\n";
        
        // Análisis del error 403
        echo "\n🔍 ANÁLISIS ERROR 403:\n";
        echo "Posibles causas:\n";
        echo "1. API KEY incorrecta o expirada\n";
        echo "2. Usuario API incorrecto\n";
        echo "3. IP no autorizada\n";
        echo "4. Cuenta desactivada\n";
        echo "5. Problema de CORS/Origen\n";
        
        echo "\n🛠 SOLUCIONES SUGERIDAS:\n";
        echo "1. Verificar API KEY con EFEVOOPAY\n";
        echo "2. Confirmar usuario API 'Famedic'\n";
        echo "3. Solicitar whitelist de tu IP\n";
        echo "4. Verificar estado de cuenta\n";
        echo "5. Probar desde otro servidor/entorno\n";
    }
    
    return $result;
}

function testAlternativeEndpoints($config) {
    echo "\n\n========================================\n";
    echo "PRUEBA DE ENDPOINTS ALTERNATIVOS\n";
    echo "========================================\n\n";
    
    $endpoints = [
        'https://api.efevoopay.com/v1/apiservice' => 'Endpoint principal alternativo',
        'https://intgapi.efevoopay.com/v1/' => 'Raíz API',
        'https://intgapi.efevoopay.com/' => 'Dominio raíz',
    ];
    
    foreach ($endpoints as $url => $desc) {
        echo "\n🔗 Probando: $desc\n";
        echo "URL: $url\n";
        
        $result = debugCurl($url, [], null);
        
        echo "HTTP Code: " . $result['code'] . "\n";
        
        if ($result['code'] == 200 || $result['code'] == 403) {
            echo "✅ Endpoint accesible\n";
        } else {
            echo "❌ Endpoint no accesible\n";
        }
    }
}

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

echo "========================================\n";
echo "DIAGNÓSTICO COMPLETO EFEVOOPAY PRODUCTIVO\n";
echo "========================================\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "Cliente: " . $config['cliente'] . "\n";
echo "API User: " . $config['api_user'] . "\n";
echo "API Key: " . substr($config['api_key'], 0, 20) . "...\n";
echo "========================================\n\n";

// 1. Verificar configuración PHP
echo "1. VERIFICACIÓN PHP:\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   cURL enabled: " . (function_exists('curl_init') ? '✅ Sí' : '❌ No') . "\n";
echo "   OpenSSL: " . (extension_loaded('openssl') ? '✅ Sí' : '❌ No') . "\n";
echo "   JSON: " . (extension_loaded('json') ? '✅ Sí' : '❌ No') . "\n";
echo "   Allow URL fopen: " . (ini_get('allow_url_fopen') ? '✅ Sí' : '❌ No') . "\n\n";

// 2. Verificar conectividad DNS
echo "2. VERIFICACIÓN DNS/RED:\n";
$host = 'intgapi.efevoopay.com';
$ip = gethostbyname($host);
echo "   Host: $host\n";
echo "   IP Resuelta: $ip\n";
echo "   Ping (2 intentos):\n";
exec("ping -c 2 $host 2>&1", $pingOutput);
foreach ($pingOutput as $line) {
    echo "   $line\n";
}
echo "\n";

// 3. Pruebas de conexión
echo "3. PRUEBAS DE CONEXIÓN:\n";
$results = testBasicConnection($config);

// 4. Prueba específica con método correcto
$apiResult = testCorrectAPIRequest($config);

// 5. Prueba endpoints alternativos
testAlternativeEndpoints($config);

// 6. Recomendaciones específicas
echo "\n\n========================================\n";
echo "RECOMENDACIONES FINALES\n";
echo "========================================\n\n";

echo "🚨 PROBLEMA IDENTIFICADO: Error 403 Forbidden\n\n";

echo "📋 ACCIONES INMEDIATAS:\n";
echo "1. 📞 Contactar a soporte@efevoopay.com con:\n";
echo "   - Tu usuario API: 'Famedic'\n";
echo "   - Tu cliente: 'GFAMEDIC'\n";
echo "   - Tu ID empresa: 1827\n";
echo "   - Código de error: 403 Forbidden\n";
echo "   - Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

echo "2. 🔑 Verificar credenciales:\n";
echo "   - Confirma API KEY: " . substr($config['api_key'], 0, 10) . "...\n";
echo "   - Confirma API USER: 'Famedic' (case-sensitive)\n";
echo "   - Pide regenerar API KEY si es necesario\n\n";

echo "3. 🌐 Verificar configuración de red:\n";
echo "   - Solicita whitelist de IPs a EFEVOOPAY\n";
echo "   - Probar desde otro servidor/red\n";
echo "   - Verificar si hay VPN o firewall bloqueando\n\n";

echo "4. 📄 Solicitar documentación actualizada:\n";
echo "   - Endpoints productivos exactos\n";
echo "   - Ejemplos de solicitudes exitosas\n";
echo "   - Códigos de error específicos\n\n";

echo "📝 COMANDO PARA SOPORTE:\n";
echo "```\n";
echo "Estimado soporte EFEVOOPAY,\n";
echo "\n";
echo "Estoy recibiendo error 403 Forbidden al intentar conectar a la API productiva.\n";
echo "\n";
echo "Datos de mi cuenta:\n";
echo "- Usuario API: Famedic\n";
echo "- Cliente: GFAMEDIC\n";
echo "- ID Empresa: 1827\n";
echo "- TOTP Secret: PIBOFBXR6P3TWXRFJQF5VRAMV5RFR3Y5\n";
echo "\n";
echo "Endpoint: https://intgapi.efevoopay.com/v1/apiservice\n";
echo "Método: getClientToken\n";
echo "Error: 403 Forbidden\n";
echo "\n";
echo "¿Podrían verificar:\n";
echo "1. Si mi API KEY es válida\n";
echo "2. Si mi IP está autorizada\n";
echo "3. Si mi cuenta está activa\n";
echo "4. El endpoint correcto productivo\n";
echo "\n";
echo "Gracias.\n";
echo "```\n";

echo "\n🛠 PARA SEGUIR PROBANDO LOCALMENTE:\n";
echo "1. Guarda este output completo\n";
echo "2. Usa Postman/Insomnia para pruebas manuales\n";
echo "3. Prueba desde un entorno diferente\n";
echo "4. Monitorea logs del servidor\n";

// 7. Guardar reporte completo
$reporte = ob_get_contents();
file_put_contents('reporte_diagnostico_' . date('Ymd_His') . '.txt', $reporte);
echo "\n\n📁 Reporte guardado en: reporte_diagnostico_" . date('Ymd_His') . ".txt\n";

echo "\n========================================\n";
echo "DIAGNÓSTICO COMPLETADO - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";