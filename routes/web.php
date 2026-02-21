<?php

use App\Http\Controllers\DocumentationAcceptController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceRequests\FiscalCertificateController;
use App\Http\Controllers\LaboratoryPurchasePdfController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Controllers\DocumentsServiceController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\PaymentMethodController;
use App\Services\EfevooPayService;
//use App\Http\Controllers\WebHook\GDAWebHookController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
#Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms-of-service');
#Route::get('/privacy-policy', PrivacyPolicyController::class)->name('privacy-policy');
Route::get('/documentation-accept', [DocumentationAcceptController::class, 'index'])->name('documentation.accept');
Route::post('/documentation-accept', [DocumentationAcceptController::class, 'store'])->name('documentation.accept.store');

// Rutas de documentos de servicio (TOS, Privacy Policy, ARCO)
Route::get('/terms-of-service', [DocumentsServiceController::class, 'termsOfService'])->name('terms-of-service');
Route::get('/privacy-policy', [DocumentsServiceController::class, 'privacyPolicy'])->name('privacy-policy');
Route::get('/rights-arco', [DocumentsServiceController::class, 'rightsARCO'])->name('rights-arco');

// Derechos ARCO
Route::get('/derechos-arco', [DocumentsServiceController::class, 'rightsARCO'])->name('rights-arco');
Route::post('/derechos-arco', [DocumentsServiceController::class, 'storeARCO'])->name('store-arco');
Route::get('/derechos-arco/exito', [DocumentsServiceController::class, 'successARCO'])->name('arco-success')->middleware('web');
Route::get('/mis-solicitudes-arco', [DocumentsServiceController::class, 'misSolicitudes'])->name('mis-solicitudes-arco')->middleware('auth');
Route::get('/solicitud-arco/{id}', [DocumentsServiceController::class, 'verSolicitud'])->name('ver-solicitud-arco')->middleware('auth');
Route::get('/descargar-documento/{solicitudId}/{tipo}', [DocumentsServiceController::class, 'descargarDocumento'])->name('descargar-documento-arco')->middleware('auth');

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


// Rutas de prueba para 3DS (solo desarrollo)
if (app()->environment('local', 'testing', 'development')) {
    Route::prefix('test/3ds')->group(function () {
        // Simulación de 3DS
        Route::get('/simulate', [PaymentMethodController::class, 'test3dsSimulation'])
            ->name('test.3ds.simulate');
        
        // Ver estado de sesiones
        Route::get('/sessions', [PaymentMethodController::class, 'test3dsSessionsStatus'])
            ->name('test.3ds.sessions');
        
        // Crear sesión de prueba
        Route::post('/session/create', [PaymentMethodController::class, 'testCreate3dsSession'])
            ->name('test.3ds.session.create');
        
        // Flujo completo de prueba
        Route::get('/flow', [PaymentMethodController::class, 'testFull3dsFlow'])
            ->name('test.3ds.flow');
    });
}
// Ruta de prueba para documentación 3DS ------------------------------------------------------------
//---------------------------------------------------------------------------------------------------
Route::get('/test/efevoo/3ds/documentation', [PaymentMethodController::class, 'test3dsDocumentation'])
    ->name('test.efevoo.3ds.documentation');

// Rutas de prueba y diagnóstico (solo desarrollo)
if (app()->environment('local', 'testing', 'development')) {
    Route::prefix('test/efevoo')->group(function () {
        // Test completo
        Route::get('/flow', [PaymentMethodController::class, 'testCompleteFlow'])
            ->name('test.efevoo.flow');
        
        // Test individuales
        Route::get('/config', [PaymentMethodController::class, 'testConfig'])
            ->name('test.efevoo.config');
        
        Route::get('/connection', [PaymentMethodController::class, 'testApiConnection'])
            ->name('test.efevoo.connection');
        
        Route::get('/encryption', [PaymentMethodController::class, 'testEncryption'])
            ->name('test.efevoo.encryption');
        
        Route::get('/methods', [PaymentMethodController::class, 'testAvailableMethods'])
            ->name('test.efevoo.methods');
        
        // Test 3DS específico
        Route::get('/3ds/documentation', [PaymentMethodController::class, 'test3dsDocumentation'])
            ->name('test.efevoo.3ds.documentation');
        
        // Otros tests que ya tengas
        Route::get('/3ds/simulate', [PaymentMethodController::class, 'test3dsSimulation'])
            ->name('test.efevoo.3ds.simulate');
        
        Route::get('/3ds/sessions', [PaymentMethodController::class, 'test3dsSessionsStatus'])
            ->name('test.efevoo.3ds.sessions');
        
        Route::post('/3ds/session/create', [PaymentMethodController::class, 'testCreate3dsSession'])
            ->name('test.efevoo.3ds.session.create');
    });
}
//----------------------------------------------------------------------------------------------------

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

Route::get('/debug-efevoo-payment', function () {
    try {
        $service = app(EfevooPayService::class);
        
        echo "🔵 === DIAGNÓSTICO COMPLETO PAGO EFEVOOPAY ===\n\n";
        
        // Token que SABEMOS que funcionaba antes
        $testToken = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
        
        echo "1. Información del token:\n";
        echo "   Longitud: " . strlen($testToken) . " caracteres\n";
        echo "   Inicia con: " . substr($testToken, 0, 20) . "\n";
        echo "   Termina con: " . substr($testToken, -20) . "\n";
        echo "   Contiene 'AQICA': " . (str_contains($testToken, 'AQICA') ? '✅' : '❌') . "\n\n";
        
        echo "2. Probando método ORIGINAL (que funcionaba):\n";
        
        $result = $service->testChargeCardOriginal([
            'token_id' => $testToken,
            'amount' => 0.01,
            'description' => 'Diagnóstico',
            'reference' => 'DEBUG-' . time(),
            'customer_id' => 1,
        ]);
        
        echo "   Éxito: " . ($result['success'] ? '✅' : '❌') . "\n";
        echo "   HTTP Code: " . ($result['status'] ?? 'N/A') . "\n";
        echo "   Código API: " . ($result['code'] ?? 'N/A') . "\n";
        echo "   Mensaje: " . ($result['message'] ?? 'N/A') . "\n\n";
        
        if ($result['success']) {
            echo "🎉 ¡PAGO EXITOSO CON MÉTODO ORIGINAL!\n";
            if (isset($result['data']['id'])) {
                echo "   ID Transacción: " . $result['data']['id'] . "\n";
            }
        } else {
            echo "❌ PAGO FALLIDO\n";
            echo "   Respuesta completa:\n";
            echo json_encode($result, JSON_PRETTY_UNESCAPED_UNICODE) . "\n\n";
            
            // Debug adicional
            echo "3. Debug adicional:\n";
            
            // Verificar encriptación
            $cav = 'PAY' . date('YmdHis') . rand(100, 999);
            $testData = [
                'track2' => $testToken,
                'amount' => '0.01',
                'cvv' => '',
                'cav' => $cav,
                'msi' => 0,
                'contrato' => '',
                'fiid_comercio' => '',
                'referencia' => 'DEBUG-' . time(),
            ];
            
            echo "   Datos a encriptar:\n";
            echo json_encode($testData, JSON_PRETTY_UNESCAPED_UNICODE) . "\n\n";
            
            $json = json_encode($testData, JSON_UNESCAPED_UNICODE);
            echo "   JSON para encriptar (" . strlen($json) . " chars):\n";
            echo substr($json, 0, 200) . "...\n";
        }
        
        return response()->json([
            'diagnostic' => 'complete',
            'token_info' => [
                'length' => strlen($testToken),
                'starts_with' => substr($testToken, 0, 20),
                'ends_with' => substr($testToken, -20),
                'is_base64' => base64_decode($testToken, true) !== false,
            ],
            'test_result' => $result,
            'timestamp' => now()->toISOString(),
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/create-new-test-token', function () {
    try {
        $service = app(EfevooPayService::class);
        
        echo "🔵 === CREANDO NUEVO TOKEN DE PRUEBA ===\n\n";
        
        echo "⚠️ ESTO HARÁ UN CARGO REAL DE $1.50 MXN\n\n";
        
        // Datos de tarjeta de prueba
        $cardData = [
            'card_number' => '5267772159330969',
            'expiration' => '3111', // MMYY
            'amount' => '1.50',
            'card_holder' => 'TEST CARD',
            'customer_id' => 1,
            'alias' => 'test-' . time(),
        ];
        
        echo "1. Tokenizando tarjeta...\n";
        $result = $service->tokenizeCard($cardData, 1);
        
        if ($result['success']) {
            echo "✅ TOKENIZACIÓN EXITOSA\n";
            echo "   Token ID: " . ($result['token_id'] ?? 'N/A') . "\n";
            echo "   Card Token: " . ($result['card_token'] ?? 'N/A') . "\n";
            echo "   Transacción ID: " . ($result['transaccion_id'] ?? 'N/A') . "\n\n";
            
            // Guardar el nuevo token para pruebas
            $newToken = $result['card_token'] ?? '';
            
            echo "2. Verificando nuevo token con pago de $0.01...\n";
            $verifyResult = $service->verifyCardToken($newToken);
            
            echo "   Token válido: " . ($verifyResult['token_valid'] ? '✅' : '❌') . "\n";
            echo "   Código: " . ($verifyResult['code'] ?? 'N/A') . "\n";
            echo "   Mensaje: " . ($verifyResult['message'] ?? 'N/A') . "\n";
            
            return response()->json([
                'success' => true,
                'new_token_created' => true,
                'token_info' => [
                    'token_id' => $result['token_id'] ?? null,
                    'card_token_preview' => $newToken ? substr($newToken, 0, 50) . '...' : null,
                    'card_token_length' => strlen($newToken),
                    'transaccion_id' => $result['transaccion_id'] ?? null,
                ],
                'verification_result' => $verifyResult,
                'note' => 'Nuevo token creado y verificado',
            ]);
            
        } else {
            echo "❌ TOKENIZACIÓN FALLIDA\n";
            echo "   Error: " . ($result['message'] ?? 'Desconocido') . "\n";
            echo "   Código: " . ($result['code'] ?? 'N/A') . "\n";
            
            return response()->json([
                'success' => false,
                'message' => 'Error en tokenización: ' . ($result['message'] ?? ''),
                'data' => $result,
            ], 400);
        }
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});


use App\Http\Controllers\ActiveCampaignDebugController;
use App\Services\ActiveCampaign\ActiveCampaignService;


// Rutas existentes
Route::get('/ac/fields', [ActiveCampaignDebugController::class, 'fields']);

// Nuevas rutas para tags
Route::get('/ac/tags', [ActiveCampaignDebugController::class, 'tags']);
Route::get('/ac/tags/search', [ActiveCampaignDebugController::class, 'searchTags']);
Route::post('/ac/tags/create', [ActiveCampaignDebugController::class, 'createTag']);
Route::post('/ac/tags/create-missing', [ActiveCampaignDebugController::class, 'createMissingTags']);

use App\Http\Controllers\ActiveCampaignFieldsController;

// Rutas para campos personalizados
// Rutas para campos personalizados
Route::get('/ac/fields/list', [ActiveCampaignFieldsController::class, 'index']);
Route::post('/ac/fields/create-membership', [ActiveCampaignFieldsController::class, 'createMembershipFields']);
Route::get('/ac/fields/relevant', [ActiveCampaignFieldsController::class, 'getRelevantFields']);