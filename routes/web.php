<?php

use App\Http\Controllers\DocumentationAcceptController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceRequests\FiscalCertificateController;
use App\Http\Controllers\LaboratoryPurchasePdfController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\WelcomeController;
//use App\Http\Controllers\WebHook\GDAWebHookController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms-of-service');
Route::get('/privacy-policy', PrivacyPolicyController::class)->name('privacy-policy');
Route::get('/documentation-accept', [DocumentationAcceptController::class, 'index'])->name('documentation.accept');
Route::post('/documentation-accept', [DocumentationAcceptController::class, 'store'])->name('documentation.accept.store');

Route::middleware([
    'auth',
    'documentation',
    'redirect-incomplete-user',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/invoice-requests/{invoice_request}/fiscal-certificate', FiscalCertificateController::class)->name('invoice-requests.fiscal-certificate');
    Route::get('/invoice/{invoice}', InvoiceController::class)->name('invoice');
    Route::get('/vendor-payments/{vendor_payment}', VendorPaymentController::class)->name('vendor-payment');
    Route::get('/laboratory-purchases/{laboratory_purchase}/results', ResultsController::class)->name('laboratory-purchases.results');
    Route::get('/laboratory-purchases/{laboratory_purchase}/download-pdf', [LaboratoryPurchasePdfController::class, 'download'])->name('laboratory-purchases.download-pdf');
    Route::post('/laboratory-purchases/{laboratory_purchase}/email-pdf', [LaboratoryPurchasePdfController::class, 'email'])->name('laboratory-purchases.email-pdf');
});

Route::get('/offline', function () {
    return 'offline';
})->name('offline');


require __DIR__.'/odessa.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/laboratories.php';
require __DIR__.'/online-pharmacy.php';
require __DIR__.'/medical-attention.php';
require __DIR__.'/auth.php';
require __DIR__.'/webhooks.php';


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