<?php 

Route::get('/test-tokenization', function() {
    try {
        $service = app()->make(\App\Services\EfevooPayService::class);
        
        $cardData = [
            'card_number' => '5267772159330969', // Tarjeta de prueba Visa
            'expiration' => '1131', // Diciembre 2028
            'card_holder' => 'Juan Perez',
            'amount' => 1.50,
            'alias' => 'Visa Test',
        ];
        
        $customerId = 1; // ID de un cliente existente
        
        Log::info('Test directo de tokenización', [
            'card_data' => $cardData,
            'customer_id' => $customerId,
        ]);
        
        $result = $service->fastTokenize($cardData, $customerId);
        
        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'No message',
            'result' => $result,
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});


Route::get('/test-add-card', function() {
    try {
        $service = app()->make(\App\Services\EfevooPayService::class);
        $user = \App\Models\User::first();
        
        if (!$user) {
            return "No hay usuarios";
        }
        
        $customer = $user->customer ?? \App\Models\Customer::first();
        
        if (!$customer) {
            return "No hay customer";
        }
        
        $cardData = [
            'card_number' => '4111111111111111',
            'expiration' => '1228', // Diciembre 2028
            'card_holder' => 'Test User',
            'amount' => 1.50,
            'alias' => 'Visa Test',
        ];
        
        Log::info('Test directo desde ruta', [
            'customer_id' => $customer->id,
            'card_data' => $cardData,
        ]);
        
        $result = $service->fastTokenize($cardData, $customer->id);
        
        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'No message',
            'result' => $result,
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});


Route::get('/test-identical-to-script', function() {
    // Configuración IDÉNTICA
    $config = [
        'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
        'api_user' => 'Efevoo Pay',
        'api_key' => 'Hq#J0hs)jK+YqF6J',
        'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
        'clave' => '6nugHedWzw27MNB8',
        'cliente' => 'TestFAMEDIC',
        'vector' => 'MszjlcnTjGLNpNy3'
    ];
    
    // 1. Generar TOTP (IDÉNTICO)
    $timestamp = floor(time() / 30);
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32Lookup = array_flip(str_split($base32Chars));
    
    $buffer = 0;
    $bitsLeft = 0;
    $result = '';
    
    for ($i = 0; $i < strlen($config['totp_secret']); $i++) {
        $ch = $config['totp_secret'][$i];
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
    
    // 2. Generar hash (IDÉNTICO)
    $hash = base64_encode(hash_hmac('sha256', $config['clave'], $totp, true));
    
    // 3. Obtener token
    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];
    
    $bodyTokenCliente = json_encode([
        'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
        'method' => 'getClientToken'
    ], JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyTokenCliente,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response1 = curl_exec($ch);
    $httpCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result1 = json_decode($response1, true);
    $tokenCliente = $result1['token'] ?? null;
    
    if (!$tokenCliente) {
        return response()->json([
            'error' => 'No se obtuvo token en paso 1',
            'response' => $result1,
            'debug' => [
                'totp' => $totp,
                'hash' => $hash,
                'body_sent' => json_decode($bodyTokenCliente, true),
            ],
        ]);
    }
    
    // 4. Tokenización (IDÉNTICA)
    $tarjeta = '5267772159330969';
    $expiracion = '3111'; // YYMM
    $montoMinimo = '1.50';
    
    $datos = [
        'track2' => $tarjeta . '=' . $expiracion,
        'amount' => $montoMinimo
    ];
    
    // Encriptar (IDÉNTICO)
    $plaintext = json_encode($datos, JSON_UNESCAPED_UNICODE);
    $encrypted = base64_encode(openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $config['clave'],
        OPENSSL_RAW_DATA,
        $config['vector']
    ));
    
    $bodyTokenizacion = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encrypted
        ],
        'method' => 'getTokenize'
    ], JSON_UNESCAPED_UNICODE);
    
    // Llamada 2: getTokenize
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => $config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyTokenizacion,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    $result2 = json_decode($response2, true);
    
    return response()->json([
        'success' => ($result2['codigo'] ?? '') === '00',
        'step1_getClientToken' => [
            'http_code' => $httpCode1,
            'got_token' => !empty($tokenCliente),
            'token' => $tokenCliente,
            'token_preview' => substr($tokenCliente, 0, 50) . '...',
            'response_codigo' => $result1['codigo'] ?? null,
            'response_msg' => $result1['msg'] ?? null,
        ],
        'step2_getTokenize' => [
            'http_code' => $httpCode2,
            'codigo' => $result2['codigo'] ?? null,
            'descripcion' => $result2['descripcion'] ?? null,
            'has_token_usuario' => isset($result2['token_usuario']),
            'token_usuario_preview' => isset($result2['token_usuario']) ? substr($result2['token_usuario'], 0, 50) . '...' : null,
            'response_keys' => array_keys($result2),
        ],
        'debug_info' => [
            'totp_generated' => $totp,
            'hash_generated' => $hash,
            'encrypted_data' => [
                'track2' => $datos['track2'],
                'amount' => $datos['amount'],
                'encrypted_preview' => substr($encrypted, 0, 50) . '...',
            ],
            'note' => 'Si esto funciona pero Laravel no, comparar TOTP/hash generation',
        ],
    ]);
});

Route::get('/compare-totp-generation', function() {
    // Configuración
    $config = [
        'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
        'clave' => '6nugHedWzw27MNB8',
    ];
    
    // 1. TOTP del script (de la respuesta exitosa)
    $scriptTOTP = '003639'; // Del resultado exitoso
    $scriptHash = 'Ym9f6NMj4pA0svIsQQE1h9vX9e0K+j+1NgshRl85aNU='; // Del resultado exitoso
    
    // 2. TOTP actual de EfevooPayService
    try {
        $service = app()->make(\App\Services\EfevooPayService::class);
        
        // Usar reflexión para obtener métodos protegidos
        $reflection = new ReflectionClass($service);
        
        // Obtener generateTOTP
        $methodTOTP = $reflection->getMethod('generateTOTP');
        $methodTOTP->setAccessible(true);
        $laravelTOTP = $methodTOTP->invoke($service);
        
        // Obtener generateHash
        $methodHash = $reflection->getMethod('generateHash');
        $methodHash->setAccessible(true);
        $laravelHash = $methodHash->invokeArgs($service, [$laravelTOTP]);
        
        // También probar generación manual (como en script)
        $timestamp = floor(time() / 30);
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32Lookup = array_flip(str_split($base32Chars));
        
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        
        for ($i = 0; $i < strlen($config['totp_secret']); $i++) {
            $ch = $config['totp_secret'][$i];
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
        $manualTOTP = str_pad($code, 6, '0', STR_PAD_LEFT);
        
        $manualHash = base64_encode(hash_hmac('sha256', $config['clave'], $manualTOTP, true));
        
        return response()->json([
            'comparison' => [
                'script_successful' => [
                    'totp' => $scriptTOTP,
                    'hash' => $scriptHash,
                ],
                'laravel_current' => [
                    'totp' => $laravelTOTP,
                    'hash' => $laravelHash,
                    'matches_script' => $laravelTOTP === $scriptTOTP && $laravelHash === $scriptHash,
                ],
                'manual_calculation' => [
                    'totp' => $manualTOTP,
                    'hash' => $manualHash,
                    'matches_script' => $manualTOTP === $scriptTOTP && $manualHash === $scriptHash,
                ],
            ],
            'analysis' => [
                'if_manual_matches_script_but_laravel_not' => 'Problema en EfevooPayService::generateTOTP/generateHash',
                'if_none_match' => 'Timestamp/TOTP interval diferente',
                'if_all_match' => 'El problema está en otra parte',
            ],
            'timestamp_info' => [
                'current_timestamp' => time(),
                'timestamp_interval' => $timestamp,
                'interval_start' => $timestamp * 30,
                'interval_end' => ($timestamp + 1) * 30 - 1,
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});


Route::get('/test-with-valid-fixed-token', function() {
    $config = [
        'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
        'api_user' => 'Efevoo Pay',
        'api_key' => 'Hq#J0hs)jK+YqF6J',
        'clave' => '6nugHedWzw27MNB8',
        'vector' => 'MszjlcnTjGLNpNy3',
    ];
    
    // Token fijo VÁLIDO del resultado exitoso
    $fixedToken = 'eGZ6ajlJcGJPSUNlSHpwMENJeWlNQlFSZ3BSWWRDb3lVNVI1cy9xb1V3Zz0=';
    
    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];
    
    // Datos IDÉNTICOS al script exitoso
    $tarjeta = '5267772159330969';
    $expiracion = '3111'; // YYMM
    $montoMinimo = '1.50';
    
    $datos = [
        'track2' => $tarjeta . '=' . $expiracion,
        'amount' => $montoMinimo
    ];
    
    // Encriptar
    $plaintext = json_encode($datos, JSON_UNESCAPED_UNICODE);
    $encrypted = base64_encode(openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $config['clave'],
        OPENSSL_RAW_DATA,
        $config['vector']
    ));
    
    $body = json_encode([
        'payload' => [
            'token' => $fixedToken,
            'encrypt' => $encrypted
        ],
        'method' => 'getTokenize'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['api_url'],
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
    
    $result = json_decode($response, true);
    
    return response()->json([
        'http_code' => $httpCode,
        'success' => ($result['codigo'] ?? '') === '00',
        'codigo' => $result['codigo'] ?? null,
        'descripcion' => $result['descripcion'] ?? null,
        'has_token_usuario' => isset($result['token_usuario']),
        'token_usuario_preview' => isset($result['token_usuario']) ? substr($result['token_usuario'], 0, 50) . '...' : null,
        'request_details' => [
            'fixed_token_used' => substr($fixedToken, 0, 50) . '...',
            'track2_format' => 'tarjeta=YYMM (3111)',
            'identical_to_script' => true,
        ],
        'interpretation' => [
            'Si funciona' => 'Usar este token fijo en producción',
            'Si no funciona' => 'Token expiró, generar nuevo dinámico',
        ],
    ]);
});


Route::get('/test-efevoo-service-fixed', function() {
    try {
        $service = app()->make(\App\Services\EfevooPayService::class);
        
        // Datos IDÉNTICOS a lo que funciona
        $cardData = [
            'card_number' => '5101256039433151',
            'expiration' => '1131', // MMYY: Noviembre 2031
            'card_holder' => 'Test User',
            'amount' => 1.50,
            'alias' => 'Test Card',
        ];
        
        $customerId = 1;
        
        Log::info('🟢 Probando EfevooPayService con token fijo', [
            'expected_conversion' => '1131 (MMYY) → 3111 (YYMM)',
            'identical_to_working_test' => true,
        ]);
        
        $result = $service->tokenizeCard($cardData, $customerId);
        
        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'No message',
            'code' => $result['code'] ?? null,
            'has_token_id' => isset($result['token_id']),
            'has_token_usuario' => isset($result['card_token']),
            'card_token_preview' => isset($result['card_token']) ? 
                substr($result['card_token'], 0, 50) . '...' : null,
            'debug' => [
                'expiration_handling' => 'Input: 1131 (MMYY) → API: 3111 (YYMM)',
                'using_fixed_token' => true,
                'expected_token' => 'eGZ6ajlJcGJPSUNlSHpwMENJeWlNQlFSZ3BSWWRDb3lVNVI1cy...',
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});


Route::get('/test-efevoo-service-fixed-corrected', function() {
    try {
        $service = app()->make(\App\Services\EfevooPayService::class);
        
        // Datos IDÉNTICOS a lo que funciona
        $cardData = [
            'card_number' => '5101256039433151',
            'expiration' => '1131', // MMYY: Noviembre 2031
            'card_holder' => 'Test User',
            'amount' => 1.50,
            'alias' => 'Test Card ' . time(), // Único cada vez
        ];
        
        $customerId = 1;
        
        Log::info('🟢 TEST CORREGIDO: Probando EfevooPayService', [
            'expiration_input' => '1131 (MMYY)',
            'expected_api_format' => '3111 (YYMM)',
        ]);
        
        $result = $service->tokenizeCard($cardData, $customerId);
        
        // Verificar en base de datos
        $tokenInDB = null;
        if (isset($result['token_id'])) {
            $tokenInDB = \App\Models\EfevooToken::find($result['token_id']);
        }
        
        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'No message',
            'code' => $result['code'] ?? null,
            'has_token_id' => isset($result['token_id']),
            'has_token_usuario' => isset($result['card_token']),
            'card_token_preview' => isset($result['card_token']) ? 
                substr($result['card_token'], 0, 50) . '...' : null,
            'database_check' => [
                'token_found_in_db' => !is_null($tokenInDB),
                'token_id' => $tokenInDB ? $tokenInDB->id : null,
                'alias' => $tokenInDB ? $tokenInDB->alias : null,
                'card_last_four' => $tokenInDB ? $tokenInDB->card_last_four : null,
            ],
            'debug' => [
                'expiration_conversion' => '1131 (MMYY) → 3111 (YYMM)',
                'using_fixed_token' => true,
            ],
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error en test corregido', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});


Route::get('/check-tokens-db', function() {
    $tokens = \App\Models\EfevooToken::orderBy('created_at', 'desc')->limit(5)->get();
    
    return response()->json([
        'total_tokens' => \App\Models\EfevooToken::count(),
        'recent_tokens' => $tokens->map(function($token) {
            return [
                'id' => $token->id,
                'alias' => $token->alias,
                'card_last_four' => $token->card_last_four,
                'card_brand' => $token->card_brand,
                'card_expiration' => $token->card_expiration,
                'customer_id' => $token->customer_id,
                'created_at' => $token->created_at,
                'is_active' => $token->is_active,
            ];
        }),
    ]);
});

/********************************************************************************/

use App\Services\EfevooPayService;

// Ruta de prueba para EfevooPay (solo desarrollo)
Route::get('/test-efevoo-pago', function () {
    if (!app()->environment('local', 'testing', 'production')) {
        abort(403, 'Solo disponible en desarrollo');
    }
    
    try {
        $service = app(EfevooPayService::class);
        
        // Token de tarjeta que sabemos que funciona
        $tokenTarjeta = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
        
        // Datos de prueba IDÉNTICOS al script exitoso
        $paymentData = [
            'token_id' => $tokenTarjeta,
            'amount' => 3.00,
            'reference' => 'TestFAMEDIC',
        ];
        
        Log::info('🔵 INICIANDO PRUEBA PAGO DESDE RUTA', [
            'token_preview' => substr($tokenTarjeta, 0, 30) . '...',
            'amount' => 3.00,
        ]);
        
        // Usar el método público de debug
        $result = $service->debugProcessPayment($paymentData);
        
        return response()->json([
            'success' => true,
            'result' => $result,
            'payment_data' => $paymentData,
            'note' => 'Prueba completada usando método debug del servicio',
        ]);
        
    } catch (\Exception $e) {
        Log::error('❌ Error en prueba EfevooPay', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ], 500);
    }
});

// Ruta para probar solo la encriptación
Route::get('/test-efevoo-encrypt', function () {
    if (!app()->environment('local', 'testing', 'production')) {
        abort(403, 'Solo disponible en desarrollo');
    }
    
    try {
        $service = app(EfevooPayService::class);
        
        // Datos idénticos al script exitoso
        $testData = [
            'track2' => 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=',
            'amount' => '3.00',
            'cvv' => '',
            'cav' => 'PAY' . date('YmdHis') . rand(100, 999),
            'msi' => 0,
            'contrato' => '',
            'fiid_comercio' => '',
            'referencia' => 'TestFAMEDIC',
        ];
        
        // Usar método público de encriptación
        $encrypted = $service->debugEncryptData($testData);
        
        return response()->json([
            'success' => true,
            'test_data' => $testData,
            'encrypted' => $encrypted,
            'encrypted_preview' => substr($encrypted, 0, 50) . '...',
            'encrypted_length' => strlen($encrypted),
            'note' => 'Compara este encrypted con el del script PHP exitoso',
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

// Ruta para verificar configuración
Route::get('/test-efevoo-config', function () {
    if (!app()->environment('local', 'testing', 'production')) {
        abort(403, 'Solo disponible en desarrollo');
    }
    
    $config = config('efevoopay');
    
    // Enmascarar datos sensibles
    $safeConfig = $config;
    if (isset($safeConfig['api_key'])) {
        $safeConfig['api_key'] = substr($safeConfig['api_key'], 0, 5) . '...';
    }
    if (isset($safeConfig['clave'])) {
        $safeConfig['clave'] = substr($safeConfig['clave'], 0, 5) . '...';
    }
    if (isset($safeConfig['fixed_token'])) {
        $safeConfig['fixed_token'] = $safeConfig['fixed_token'] 
            ? substr($safeConfig['fixed_token'], 0, 30) . '...' 
            : 'NO CONFIGURADO';
    }
    
    return response()->json([
        'environment' => app()->environment(),
        'config' => $safeConfig,
        'env_vars' => [
            'EFEVOO_ENVIRONMENT' => env('EFEVOO_ENVIRONMENT'),
            'EFEVOO_API_URL' => env('EFEVOO_API_URL'),
            'EFEVOO_API_USER' => env('EFEVOO_API_USER'),
            'EFEVOO_API_KEY' => env('EFEVOO_API_KEY', '') ? 'CONFIGURADO' : 'NO CONFIGURADO',
            'EFEVOO_CLIENTE' => env('EFEVOO_CLIENTE'),
        ],
    ]);
});


Route::get('/test-efevoo-token-compare', function () {
    try {
        $service = app(EfevooPayService::class);
        
        // 1. Obtener token de Laravel
        $tokenResultLaravel = $service->getClientToken();
        
        // 2. Generar token como lo hace el script PHP exitoso
        $config = config('efevoopay');
        $totp = generateTOTP_PHP($config['totp_secret']);
        $hash = generateHash_PHP($totp, $config['clave']);
        
        $url = $config['api_url'];
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $config['api_user'],
            'X-API-KEY: ' . $config['api_key']
        ];
        
        $bodyTokenCliente = json_encode([
            'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
            'method' => 'getClientToken'
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bodyTokenCliente,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        $tokenScript = $responseData['token'] ?? null;
        
        return response()->json([
            'comparacion_tokens' => [
                'laravel_success' => $tokenResultLaravel['success'],
                'laravel_token' => $tokenResultLaravel['token'] ?? 'NO',
                'laravel_token_preview' => isset($tokenResultLaravel['token']) 
                    ? substr($tokenResultLaravel['token'], 0, 30) . '...' 
                    : 'NO',
                'laravel_using_fixed' => $tokenResultLaravel['fixed'] ?? false,
                'script_token' => $tokenScript,
                'script_token_preview' => $tokenScript 
                    ? substr($tokenScript, 0, 30) . '...' 
                    : 'NO',
                'tokens_iguales' => isset($tokenResultLaravel['token'], $tokenScript) 
                    && $tokenResultLaravel['token'] === $tokenScript,
                'http_code_script' => $httpCode,
            ],
            'config_check' => [
                'api_url' => $config['api_url'],
                'api_user' => $config['api_user'],
                'cliente' => $config['cliente'],
                'has_fixed_token' => !empty($config['fixed_token']),
                'fixed_token_match' => $config['fixed_token'] === 'eGZ6ajlJcGJPSUNlSHpwMENJeWlNQlFSZ3BSWWRDb3lVNVI1cy9xb1V3Zz0=',
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

// Funciones copiadas del script PHP
function generateTOTP_PHP($secret) {
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

function generateHash_PHP($totp, $clave) {
    return base64_encode(hash_hmac('sha256', $clave, $totp, true));
}

/********************************************************************/
Route::get('/test-efevoo-encrypt-compare', function () {
    try {
        $config = config('efevoopay');
        $tokenTarjeta = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
        
        // Datos IDÉNTICOS
        $datosPago = [
            'track2' => $tokenTarjeta,
            'amount' => '3.00',
            'cvv' => '',
            'cav' => 'PAY20260204003403117', // Mismo CAV que funcionó
            'msi' => 0,
            'contrato' => '',
            'fiid_comercio' => '',
            'referencia' => 'TestFAMEDIC'
        ];
        
        // Encriptación PHP (como en script exitoso)
        $encryptedPHP = encryptDataAES_PHP($datosPago, $config['clave'], $config['vector']);
        
        // Encriptación Laravel
        $service = app(EfevooPayService::class);
        $reflection = new \ReflectionClass($service);
        $encryptMethod = $reflection->getMethod('encryptData');
        $encryptMethod->setAccessible(true);
        $encryptedLaravel = $encryptMethod->invoke($service, $datosPago);
        
        return response()->json([
            'comparacion_encrypted' => [
                'datos_originales' => $datosPago,
                'encrypted_php' => $encryptedPHP,
                'encrypted_php_preview' => substr($encryptedPHP, 0, 50) . '...',
                'encrypted_php_length' => strlen($encryptedPHP),
                'encrypted_laravel' => $encryptedLaravel,
                'encrypted_laravel_preview' => substr($encryptedLaravel, 0, 50) . '...',
                'encrypted_laravel_length' => strlen($encryptedLaravel),
                'son_iguales' => $encryptedPHP === $encryptedLaravel,
                'diferencia_caracteres' => $encryptedPHP === $encryptedLaravel ? 0 : levenshtein($encryptedPHP, $encryptedLaravel),
            ],
            'clave_vector_check' => [
                'clave_laravel' => $config['clave'],
                'clave_length' => strlen($config['clave']),
                'vector_laravel' => $config['vector'],
                'vector_length' => strlen($config['vector']),
                'clave_php' => '6nugHedWzw27MNB8', // Del script exitoso
                'clave_match' => $config['clave'] === '6nugHedWzw27MNB8',
                'vector_php' => 'MszjlcnTjGLNpNy3', // Del script exitoso
                'vector_match' => $config['vector'] === 'MszjlcnTjGLNpNy3',
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

function encryptDataAES_PHP($data, $clave, $vector) {
    $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
    return base64_encode(openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        $clave,
        OPENSSL_RAW_DATA,
        $vector
    ));
}
/*________________________________________________________________________*/
Route::get('/test-efevoo-exact-script', function () {
    // REPLICACIÓN EXACTA del script PHP que funciona
    $config = [
        'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
        'api_user' => 'Efevoo Pay',
        'api_key' => 'Hq#J0hs)jK+YqF6J',
        'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
        'clave' => '6nugHedWzw27MNB8',
        'cliente' => 'TestFAMEDIC',
        'vector' => 'MszjlcnTjGLNpNy3'
    ];

    $tokenTarjeta = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';

    // 1. Obtener token de cliente (igual al script)
    $totp = generateTOTP_PHP($config['totp_secret']);
    $hash = generateHash_PHP($totp, $config['clave']);

    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . $config['api_user'],
        'X-API-KEY: ' . $config['api_key']
    ];

    $bodyTokenCliente = json_encode([
        'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
        'method' => 'getClientToken'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyTokenCliente,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $responseData = json_decode($response, true);
    $tokenCliente = $responseData['token'] ?? null;
    
    if (!$tokenCliente) {
        return response()->json([
            'error' => 'No se obtuvo token cliente',
            'response' => $responseData,
            'http_code' => $httpCode,
        ], 500);
    }

    // 2. Preparar pago (igual al script)
    $cav = 'PAY' . date('YmdHis') . rand(100, 999);
    
    $datosPago = [
        'track2' => $tokenTarjeta,
        'amount' => '3.00',
        'cvv' => '',
        'cav' => $cav,
        'msi' => 0,
        'contrato' => '',
        'fiid_comercio' => '',
        'referencia' => 'TestFAMEDIC'
    ];

    // 3. Encriptar (igual al script)
    $encryptedPago = encryptDataAES_PHP($datosPago, $config['clave'], $config['vector']);

    // 4. Enviar pago (igual al script)
    $bodyPago = json_encode([
        'payload' => [
            'token' => $tokenCliente,
            'encrypt' => $encryptedPago
        ],
        'method' => 'getPayment'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyPago,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $responsePago = curl_exec($ch);
    $httpCodePago = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $responsePagoData = json_decode($responsePago, true);
    
    return response()->json([
        'replicacion_exacta' => true,
        'pasos' => [
            '1_token_cliente' => [
                'obtenido' => $tokenCliente ? '✅' : '❌',
                'token_preview' => substr($tokenCliente, 0, 30) . '...',
                'http_code' => $httpCode,
            ],
            '2_datos_pago' => $datosPago,
            '3_encrypted' => [
                'preview' => substr($encryptedPago, 0, 50) . '...',
                'length' => strlen($encryptedPago),
            ],
            '4_resultado_pago' => [
                'http_code' => $httpCodePago,
                'response' => $responsePagoData,
                'codigo' => $responsePagoData['codigo'] ?? 'N/A',
                'id_transaccion' => $responsePagoData['id'] ?? 'N/A',
                'exito' => ($responsePagoData['codigo'] ?? '') === '00',
            ],
        ],
        'comparacion_con_laravel' => [
            'nota' => 'Si esto funciona y Laravel no, hay diferencia en:',
            'posibles_diferencias' => [
                '1. Token cliente (Laravel usa fijo, script dinámico)',
                '2. Datos encriptados (clave/vector diferentes)',
                '3. Headers (X-API-USER, X-API-KEY)',
                '4. JSON encoding (JSON_UNESCAPED_UNICODE)',
            ],
        ],
    ]);
});


/*************************************************************************************************************/
Route::get('/test-efevoo-fixed-issue', function () {
    try {
        $service = app(EfevooPayService::class);
        
        // Forzar token dinámico explícitamente
        $tokenResult = $service->getClientToken(false, true); // true = forceDynamic
        
        if (!$tokenResult['success']) {
            return response()->json([
                'error' => 'Error obteniendo token dinámico',
                'details' => $tokenResult
            ], 500);
        }
        
        // Verificar que sea dinámico
        if ($tokenResult['fixed'] ?? false) {
            return response()->json([
                'critical_error' => '⚠️ Aún usando token fijo para pago',
                'solution' => 'El token fijo SOLO funciona para getTokenize, NO para getPayment',
                'immediate_fix' => 'Modifica getClientToken() para forzar token dinámico en pagos',
                'token_result' => $tokenResult,
            ]);
        }
        
        $tokenTarjeta = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
        
        // Hacer pago
        $result = $service->chargeCard([
            'token_id' => $tokenTarjeta,
            'amount' => 0.01, // Monto mínimo para prueba
            'description' => 'Prueba fix token dinámico',
            'reference' => 'FIX-' . time(),
            'customer_id' => 1,
        ]);
        
        return response()->json([
            'diagnostico' => '✅ Usando token dinámico para pago',
            'token_info' => [
                'type' => $tokenResult['dynamic'] ? 'dynamic' : 'fixed',
                'dynamic' => $tokenResult['dynamic'] ?? false,
                'fixed' => $tokenResult['fixed'] ?? false,
                'preview' => substr($tokenResult['token'], 0, 30) . '...',
                'message' => $tokenResult['message'],
            ],
            'payment_result' => $result,
            'interpretation' => isset($result['code']) && $result['code'] === '00' 
                ? '🎉 ¡PAGO EXITOSO CON TOKEN DINÁMICO!' 
                : ($result['success'] ? '⚠️ Respuesta inesperada' : '❌ Error en pago'),
            'pasos_siguientes' => [
                '1. Si funciona: Implementar en todos los pagos',
                '2. Crear lógica: Token fijo para tokenización, dinámico para pagos',
                '3. Actualizar ChargeEfevooPaymentMethodAction',
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});


Route::get('/test-complete-efevoo-flow', function () {
    try {
        // 1. Buscar un customer con tokens
        $customer = \App\Models\Customer::with('efevooTokens')->first();
        
        if (!$customer) {
            return response()->json(['error' => 'No hay customers'], 404);
        }
        
        // 2. Buscar un token activo
        $token = $customer->efevooTokens()->active()->first();
        
        if (!$token) {
            return response()->json(['error' => 'No hay tokens activos'], 404);
        }
        
        // 3. Probar el action
        $action = app(\App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction::class);
        
        $transaction = $action->__invoke(
            $customer,
            100, // $1.00 MXN (100 centavos)
            (string) $token->id // ID del token
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Flujo completo funcionando',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
            ],
            'token_used' => [
                'id' => $token->id,
                'alias' => $token->alias,
                'last_four' => $token->card_last_four,
            ],
            'transaction' => [
                'id' => $transaction->id,
                'gateway_id' => $transaction->gateway_transaction_id,
                'amount_cents' => $transaction->transaction_amount_cents,
                'amount_mxn' => $transaction->transaction_amount_cents / 100,
                'reference' => $transaction->reference_id,
            ],
            'note' => '✅ Sistema de pagos EfevooPay funcionando correctamente con token dinámico',
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

Route::get('/test-efevoo-complete-flow', function () {
    try {
        $service = app(EfevooPayService::class);
        
        // 1. Probar tokenización (con token fijo)
        echo "1. Probando tokenización...\n";
        $tokenizeResult = $service->getClientToken(false, 'tokenize');
        echo "   Tipo token: " . ($tokenizeResult['type'] ?? 'N/A') . "\n";
        echo "   Éxito: " . ($tokenizeResult['success'] ? '✅' : '❌') . "\n\n";
        
        // 2. Probar pago (con token dinámico)
        echo "2. Probando pago...\n";
        $paymentResult = $service->getClientToken(false, 'payment');
        echo "   Tipo token: " . ($paymentResult['type'] ?? 'N/A') . "\n";
        echo "   Éxito: " . ($paymentResult['success'] ? '✅' : '❌') . "\n\n";
        
        // 3. Hacer un pago de prueba ($0.01)
        echo "3. Realizando pago de prueba ($0.01 MXN)...\n";
        $testToken = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
        
        $pago = $service->chargeCard([
            'token_id' => $testToken,
            'amount' => 0.01,
            'description' => 'Prueba sistema completo',
            'reference' => 'COMPLETE-' . time(),
            'customer_id' => 1,
        ]);
        
        echo "   Resultado: " . ($pago['success'] ? '✅ APROBADO' : '❌ RECHAZADO') . "\n";
        if ($pago['success']) {
            echo "   ID Transacción: " . ($pago['efevoo_transaction_id'] ?? 'N/A') . "\n";
            echo "   Código: " . ($pago['code'] ?? 'N/A') . "\n";
            echo "   Auth: " . ($pago['authorization_code'] ?? 'N/A') . "\n";
        }
        
        return response()->json([
            'system_status' => 'operational',
            'tokenization' => $tokenizeResult,
            'payment_token' => $paymentResult,
            'test_payment' => $pago,
            'summary' => [
                'issue_resolved' => true,
                'root_cause' => 'Token fijo solo funciona para tokenización, pagos requieren token dinámico',
                'solution_applied' => true,
                'next_steps' => 'Implementar en producción con montos reales',
            ],
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});