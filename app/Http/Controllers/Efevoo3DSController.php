<?php
namespace App\Http\Controllers;

use App\Services\EfevooPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Efevoo3DSController extends Controller
{
    protected $efevooService;

    public function __construct(EfevooPayService $efevooService)
    {
        $this->efevooService = $efevooService;
    }

    /**
     * Iniciar proceso 3DS para tokenización
     */
    public function initiate3DS(Request $request)
    {
        $validated = $request->validate([
            'card_number' => 'required|string|size:16',
            'expiration' => 'required|string|size:4',
            'cvv' => 'required|string|min:3|max:4',
            'card_holder' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'customer_id' => 'required|integer',
        ]);

        try {
            $result = $this->efevooService->initiate3DSProcess($validated, $validated['customer_id']);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'session_id' => $result['session_id'],
                    'requires_3ds' => $result['requires_3ds'] ?? false,
                    'next_step' => $result['requires_3ds'] ? 'redirect_to_3ds' : 'tokenize_directly',
                    'data' => $result,
                ]);
            }

            return response()->json($result, 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verificar estado de sesión 3DS
     */
    public function checkStatus(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'order_id' => 'required|integer',
        ]);

        try {
            // En un caso real, necesitarías recuperar los datos de la tarjeta de storage seguro
            $cardData = [
                'card_number' => '', // Se obtendría de la sesión
                'expiration' => '',
                'cvv' => '',
            ];

            $result = $this->efevooService->check3DSStatus(
                $validated['session_id'],
                $validated['order_id'],
                $cardData
            );

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Callback de 3DS (para redirección bancaria)
     */
    public function handleCallback(Request $request)
    {
        Log::info('3DS Callback recibido', $request->all());

        try {
            $result = $this->efevooService->handle3DSCallback($request->all());

            // Redirigir al frontend con el resultado
            if (isset($result['session_id'])) {
                return redirect()->away(
                    config('app.frontend_url') . 
                    '/payment/3ds/callback?session_id=' . $result['session_id'] .
                    '&success=' . ($result['success'] ? 'true' : 'false')
                );
            }

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Error en callback 3DS', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Realizar reembolso
     */
    public function refundTransaction(Request $request, $transactionId)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->efevooService->refundTransaction(
                $transactionId,
                $validated['amount'] ?? null
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Reembolso procesado exitosamente',
                    'refund_id' => $result['refund_id'],
                    'refund_transaction' => $result['refund_transaction'] ?? null,
                ]);
            }

            return response()->json($result, 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
 * Test específico para 3DS según documentación
 */
public function test3dsDocumentation(Request $request)
{
    Log::info('🔵 ========== TEST 3DS SEGÚN DOCUMENTACIÓN ==========');

    try {
        $service = $this->efevooPayService;

        // 1. Test de cifrado
        $encryptionTest = [];
        if (method_exists($service, 'test3DSEncryption')) {
            $encryptionTest = $service->test3DSEncryption();
        }

        // 2. Probar con datos EXACTOS de la documentación
        $exactDocData = [
            'track' => '5123000011112222',
            'cvv' => '111',
            'exp' => '11/11', // Formato MM/YY
            'fiid_comercio' => '123678', // De la documentación
            'msi' => 0,
            'amount' => '1.00',
            'browser' => [
                'browserAcceptHeader' => 'application/json',
                'browserJavaEnabled' => false,
                'browserJavaScriptEnabled' => true,
                'browserLanguage' => 'es-419',
                'browserTZ' => '360', // NOTA: Sin signo, como en la doc
                'browserUserAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            ],
        ];

        Log::info('🔵 Datos exactos de documentación', $exactDocData);

        // 3. Obtener token
        $tokenResult = $service->getClientToken(false, 'tokenize');
        
        if (!$tokenResult['success']) {
            throw new \Exception('Error obteniendo token: ' . $tokenResult['message']);
        }

        // 4. Encriptar
        if (method_exists($service, 'encryptData3DS')) {
            $encrypted = $service->encryptData3DS($exactDocData);
            
            // 5. Crear payload exacto como documentación
            $payload = [
                'payload' => [
                    'token' => $tokenResult['token'],
                    'encrypt' => $encrypted,
                ],
                'method' => 'payments3DS_GetLink',
            ];

            Log::info('🔵 Payload para enviar (según doc)', [
                'method' => $payload['method'],
                'token_preview' => substr($payload['payload']['token'], 0, 30) . '...',
                'encrypted_preview' => substr($payload['payload']['encrypt'], 0, 50) . '...',
                'encrypted_length' => strlen($payload['payload']['encrypt']),
            ]);

            // 6. Enviar a API
            $response = $service->makeApiRequest($payload);

            Log::info('🔵 Respuesta de API con datos de documentación', [
                'success' => $response['success'] ?? false,
                'status' => $response['status'] ?? null,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
                'has_data' => !empty($response['data']),
                'data_keys' => !empty($response['data']) ? array_keys($response['data']) : [],
            ]);

            return response()->json([
                'test' => '3DS según documentación',
                'timestamp' => now()->toISOString(),
                'encryption_test' => $encryptionTest,
                'sent_data' => $exactDocData,
                'token_info' => [
                    'success' => $tokenResult['success'],
                    'type' => $tokenResult['type'] ?? 'unknown',
                    'token_preview' => $tokenResult['success'] ? substr($tokenResult['token'], 0, 30) . '...' : 'N/A',
                ],
                'payload_sent' => [
                    'method' => $payload['method'],
                    'token_length' => strlen($payload['payload']['token']),
                    'encrypted_length' => strlen($payload['payload']['encrypt']),
                ],
                'api_response' => $response,
                'analysis' => [
                    'is_3ds_available' => $response['success'] ?? false,
                    'error_if_any' => !$response['success'] ? $response['message'] : 'None',
                    'has_url_3dsecure' => isset($response['data']['url_3dsecure']),
                    'has_token_3dsecure' => isset($response['data']['token_3dsecure']),
                    'next_step' => $response['success'] ? 
                        (isset($response['data']['url_3dsecure']) ? 'Redirect to iframe' : 'Call GetStatus') : 
                        'Fix the error',
                ],
            ]);
        } else {
            throw new \Exception('Método encryptData3DS no disponible');
        }

    } catch (\Exception $e) {
        Log::error('❌ ERROR EN TEST DOCUMENTACIÓN', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
}
}