<?php
require 'vendor/autoload.php'; // Si usas Composer

// Credenciales proporcionadas
$credenciales = [
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q', // Secret para TOTP
    'cliente' => 'TestFAMEDIC', // Cliente
    'clave' => '6nugHedWzw27MNB8', // Clave para el hash
    'vector' => 'MszjlcnTjGLNpNy3' // Vector (para futuras operaciones)
];

// Configuración
$api_url = 'https://test-intgapi.efevoopay.com/v1/apiservice';
$api_user = 'Efevoo Pay';
$api_key = 'Hq#J0hs)jK+YqF6J';

/**
 * Genera código TOTP usando el secret
 * Necesitarás una librería TOTP. Puedes usar:
 * composer require christian-riesen/otp
 * o implementar tu propia solución
 */
function generateTOTP($secret) {
    // Método 1: Usando librería OTP (recomendado)
    // $totp = new \OTPHP\TOTP($secret);
    // return $totp->now();
    
    // Método 2: Implementación básica (solo para pruebas)
    // NOTA: Esta implementación puede no ser exacta, mejor usa una librería
    $timestamp = floor(time() / 30);
    $secretKey = base32_decode($secret);
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

/**
 * Decodifica Base32
 */
function base32_decode($base32) {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32Lookup = array_flip(str_split($base32Chars));
    
    $buffer = 0;
    $bitsLeft = 0;
    $result = '';
    
    for ($i = 0; $i < strlen($base32); $i++) {
        $ch = $base32[$i];
        if (!isset($base32Lookup[$ch])) continue;
        
        $buffer = ($buffer << 5) | $base32Lookup[$ch];
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    
    return $result;
}

/**
 * Genera el hash HMAC-SHA256 en Base64
 */
function generateHash($totpCode, $clave) {
    // La clave del HMAC es el código TOTP
    // El mensaje es la clave proporcionada por IT
    return base64_encode(hash_hmac('sha256', $clave, $totpCode, true));
}

/**
 * Método alternativo si tienes problemas con TOTP
 * Usando el token anual que ya te proporcionaron
 */
function testWithAnnualToken() {
    global $api_url, $api_user, $api_key, $credenciales;
    
    $client = new GuzzleHttp\Client();
    $headers = [
        'Content-Type' => 'application/json',
        'X-API-USER' => $api_user,
        'X-API-KEY' => $api_key,
    ];
    
    // Prueba 1: Con el token anual
    echo "=== Prueba 1: Usando token anual proporcionado ===\n";
    $body = json_encode([
        'payload' => [
            'hash' => 'hash_generado_con_totp', // Aquí debería ir el hash correcto
            'cliente' => $credenciales['cliente']
        ],
        'method' => 'getClientToken'
    ]);
    
    try {
        $response = $client->request('POST', $api_url, [
            'headers' => $headers,
            'body' => $body,
            'verify' => false, // Solo para pruebas, en producción usar SSL
            'timeout' => 30
        ]);
        
        echo "Status: " . $response->getStatusCode() . "\n";
        echo "Response: " . $response->getBody() . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

/**
 * Prueba de generación de hash
 */
function testHashGeneration() {
    global $credenciales;
    
    echo "=== Pruebas de generación de hash ===\n";
    
    // Generar TOTP
    $totp = generateTOTP($credenciales['totp_secret']);
    echo "TOTP generado: $totp\n";
    
    // Generar hash
    $hash = generateHash($totp, $credenciales['clave']);
    echo "Hash generado: $hash\n";
    echo "Longitud del hash: " . strlen($hash) . "\n";
    
    return [
        'totp' => $totp,
        'hash' => $hash
    ];
}

/**
 * Enviar solicitud a la API
 */
function sendRequest($hash) {
    global $api_url, $api_user, $api_key, $credenciales;
    
    $client = new GuzzleHttp\Client();
    $headers = [
        'Content-Type' => 'application/json',
        'X-API-USER' => $api_user,
        'X-API-KEY' => $api_key,
    ];
    
    $body = json_encode([
        'payload' => [
            'hash' => $hash,
            'cliente' => $credenciales['cliente']
        ],
        'method' => 'getClientToken'
    ]);
    
    echo "\n=== Enviando solicitud a la API ===\n";
    echo "URL: $api_url\n";
    echo "Headers:\n";
    print_r($headers);
    echo "Body:\n";
    echo $body . "\n";
    
    try {
        $response = $client->request('POST', $api_url, [
            'headers' => $headers,
            'body' => $body,
            'verify' => false, // Solo para pruebas
            'timeout' => 30,
            'http_errors' => false // Para manejar errores HTTP manualmente
        ]);
        
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();
        
        echo "\n=== Respuesta de la API ===\n";
        echo "Status Code: $statusCode\n";
        echo "Response Body:\n";
        echo $responseBody . "\n";
        
        $responseData = json_decode($responseBody, true);
        
        if (isset($responseData['codigo'])) {
            switch ($responseData['codigo']) {
                case '100':
                    echo "✓ Éxito: Token generado correctamente\n";
                    echo "Token: " . $responseData['token'] . "\n";
                    break;
                case '102':
                    echo "✗ Error: Credenciales incorrectas\n";
                    echo "Mensaje: " . ($responseData['msg'] ?? 'Sin mensaje') . "\n";
                    break;
                default:
                    echo "⚠ Código de respuesta: " . $responseData['codigo'] . "\n";
            }
        }
        
        return $responseData;
        
    } catch (Exception $e) {
        echo "✗ Error en la solicitud: " . $e->getMessage() . "\n";
        return null;
    }
}

// Script principal
function main() {
    global $credenciales;
    
    echo "========================================\n";
    echo "Pruebas de Integración EfevooPay\n";
    echo "========================================\n";
    
    // Mostrar credenciales (sin la clave completa por seguridad)
    echo "Cliente: " . $credenciales['cliente'] . "\n";
    echo "TOTP Secret: " . substr($credenciales['totp_secret'], 0, 8) . "...\n";
    echo "Clave: " . substr($credenciales['clave'], 0, 4) . "...\n";
    echo "\n";
    
    // Prueba de generación de hash
    $generated = testHashGeneration();
    
    // Enviar solicitud con hash generado
    $response = sendRequest($generated['hash']);
    
    // Si falla, probar con diferentes variaciones
    if ($response && $response['codigo'] == '102') {
        echo "\n=== Probando variaciones ===\n";
        
        // Variación 1: Invertir key y message
        echo "\nVariación 1: Invertir key y message\n";
        $hashV1 = base64_encode(hash_hmac('sha256', $generated['totp'], $credenciales['clave'], true));
        sendRequest($hashV1);
        
        // Variación 2: Usar clave como key, TOTP como message
        echo "\nVariación 2: Usar clave como key\n";
        $hashV2 = base64_encode(hash_hmac('sha256', $credenciales['clave'], $generated['totp'], true));
        sendRequest($hashV2);
    }
}

// Ejecutar el script
main();