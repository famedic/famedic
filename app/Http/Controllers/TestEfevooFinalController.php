<?php

namespace App\Http\Controllers;

use App\Services\EfevooPayService;
use App\Models\EfevooToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestEfevooFinalController extends Controller
{
    protected $efevooService;
    
    public function __construct(EfevooPayService $efevooService)
    {
        $this->efevooService = $efevooService;
    }
    
    public function testWithCorrectFormat()
    {
        Log::info('=== TEST FORMATO CORRECTO ===');
        
        // Tarjeta que funciona según tu script
        $cardData = [
            'card_number' => '5267772159330969',
            'expiration' => '3111', // MMYY - Noviembre 2031
            'card_holder' => 'TEST USER',
            'amount' => 1.50, // $1.50 MXN - mínimo según pruebas
        ];
        
        Log::info('Datos de tarjeta', [
            'last_four' => substr($cardData['card_number'], -4),
            'expiration' => $cardData['expiration'],
            'amount' => $cardData['amount'],
        ]);
        
        // Probar tokenización
        $result = $this->efevooService->tokenizeCard($cardData, 1);
        
        if ($result['success']) {
            $tokenId = $result['token_id'];
            $cardToken = $result['card_token'];
            
            Log::info('✅ Tokenización exitosa', [
                'token_id' => $tokenId,
                'card_token_preview' => substr($cardToken, 0, 20) . '...',
            ]);
            
            // Ahora probar pago con el token
            $paymentData = [
                'amount' => 10.00, // $10.00 MXN
                'cav' => 'TEST-' . time(),
                'referencia' => 'TEST-PAY-' . time(),
                'description' => 'Pago de prueba con token',
                'msi' => 0,
            ];
            
            Log::info('Probando pago con token', [
                'token_id' => $tokenId,
                'amount' => $paymentData['amount'],
                'reference' => $paymentData['referencia'],
            ]);
            
            $paymentResult = $this->efevooService->processPayment($paymentData, $tokenId);
            
            return response()->json([
                'tokenization' => [
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'token_id' => $tokenId,
                    'card_token_preview' => substr($cardToken, 0, 20) . '...',
                    'transaction_id' => $result['transaction']->id ?? null,
                ],
                'payment' => [
                    'success' => $paymentResult['success'],
                    'message' => $paymentResult['message'],
                    'codigo' => $paymentResult['codigo'],
                    'transaction_id' => $paymentResult['transaction_id'] ?? null,
                    'reference' => $paymentResult['reference'] ?? null,
                ],
                'logs' => 'Revisa storage/logs/laravel.log para detalles',
            ]);
            
        } else {
            Log::error('❌ Tokenización fallida', [
                'message' => $result['message'],
                'codigo' => $result['codigo'] ?? null,
                'errors' => $result['errors'] ?? null,
            ]);
            
            return response()->json([
                'error' => 'Tokenización fallida',
                'message' => $result['message'],
                'codigo' => $result['codigo'] ?? null,
                'errors' => $result['errors'] ?? null,
            ], 400);
        }
    }
    
    public function testDirectPayment()
    {
        // Probar pago directo sin tokenización previa
        $paymentData = [
            'card_number' => '5267772159330969',
            'expiration' => '3111', // MMYY
            'card_holder' => 'TEST USER',
            'cvv' => '123',
            'amount' => 10.00,
            'cav' => 'DIRECT-' . time(),
            'referencia' => 'DIRECT-PAY-' . time(),
            'description' => 'Pago directo sin token',
            'msi' => 0,
        ];
        
        Log::info('Probando pago directo', [
            'last_four' => substr($paymentData['card_number'], -4),
            'amount' => $paymentData['amount'],
            'reference' => $paymentData['referencia'],
        ]);
        
        $result = $this->efevooService->processPayment($paymentData);
        
        return response()->json($result);
    }
    
    public function listTokens()
    {
        $tokens = EfevooToken::all();
        
        return response()->json([
            'tokens' => $tokens->map(function($token) {
                return [
                    'id' => $token->id,
                    'alias' => $token->alias,
                    'card_last_four' => $token->card_last_four,
                    'card_brand' => $token->card_brand,
                    'card_expiration' => $token->card_expiration,
                    'card_holder' => $token->card_holder,
                    'environment' => $token->environment,
                    'is_active' => $token->is_active,
                    'created_at' => $token->created_at->toDateTimeString(),
                    'has_transactions' => $token->transactions()->count(),
                ];
            }),
            'total' => $tokens->count(),
        ]);
    }
}