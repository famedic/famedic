<?php
// app/Http/Controllers/Api/EfevooPayController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EfevooPay\TokenizeCardRequest;
use App\Http\Requests\EfevooPay\ProcessPaymentRequest;
use App\Http\Requests\EfevooPay\RefundRequest;
use App\Http\Requests\EfevooPay\SearchTransactionsRequest;
use App\Services\EfevooPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EfevooPayController extends Controller
{
    protected EfevooPayService $efevooPayService;
    
    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }
    
    /**
     * @OA\Get(
     *     path="/api/efevoopay/health",
     *     summary="Health check de la API EfevooPay",
     *     tags={"EfevooPay"},
     *     @OA\Response(
     *         response=200,
     *         description="Estado de la API"
     *     )
     * )
     */
    public function healthCheck(): JsonResponse
    {
        try {
            $result = $this->efevooPayService->healthCheck();
            
            return response()->json([
                'success' => $result['status'] === 'online',
                'data' => $result,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            Log::error('EfevooPay Health Check Error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar estado de EfevooPay',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Post(
     *     path="/api/efevoopay/tokenize",
     *     summary="Tokenizar una tarjeta",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"card_number", "expiration", "card_holder", "amount"},
     *             @OA\Property(property="card_number", type="string", example="5267772159330969"),
     *             @OA\Property(property="expiration", type="string", example="3111"),
     *             @OA\Property(property="card_holder", type="string", example="JUAN PEREZ"),
     *             @OA\Property(property="amount", type="number", format="float", example=1.50),
     *             @OA\Property(property="save_token", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tarjeta tokenizada exitosamente"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Error de validación"
     *     )
     * )
     */
    public function tokenizeCard(TokenizeCardRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $data = $request->validated();
            
            // Advertencia sobre cargos reales
            Log::warning('EfevooPay Tokenization Attempt', [
                'user_id' => $user->id,
                'amount' => $data['amount'],
                'card_last_four' => substr($data['card_number'], -4),
            ]);
            
            $result = $this->efevooPayService->tokenizeCard($data, $user->id);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tarjeta tokenizada exitosamente',
                    'token_id' => $result['token_id'],
                    'card_token' => $result['card_token'],
                    'transaction' => $result['transaction'],
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
                'code' => $result['codigo'] ?? null,
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Tokenization Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al tokenizar tarjeta',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Post(
     *     path="/api/efevoopay/payment",
     *     summary="Procesar un pago",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "cav", "referencia", "description"},
     *             @OA\Property(property="token_id", type="integer", example=1),
     *             @OA\Property(property="amount", type="number", format="float", example=100.00),
     *             @OA\Property(property="cav", type="string", example="ABC123DEF456"),
     *             @OA\Property(property="cvv", type="string", example="123"),
     *             @OA\Property(property="msi", type="integer", example=0),
     *             @OA\Property(property="contrato", type="string", example=""),
     *             @OA\Property(property="fiid_comercio", type="string", example=""),
     *             @OA\Property(property="referencia", type="string", example="ORD-20250127-001"),
     *             @OA\Property(property="description", type="string", example="Pago de servicios médicos"),
     *             @OA\Property(property="card_number", type="string", example="5267772159330969"),
     *             @OA\Property(property="expiration", type="string", example="3111"),
     *             @OA\Property(property="card_holder", type="string", example="JUAN PEREZ")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Pago procesado exitosamente"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Error de validación"
     *     )
     * )
     */
    public function processPayment(ProcessPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $user = $request->user();
            
            // Si se usa token, verificar pertenencia
            if (isset($data['token_id'])) {
                $token = \App\Models\EfevooToken::where('client_id', $user->id)
                    ->find($data['token_id']);
                
                if (!$token) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token de tarjeta no encontrado o no pertenece al usuario',
                    ], 404);
                }
            }
            
            $result = $this->efevooPayService->processPayment($data, $data['token_id'] ?? null);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'reference' => $result['reference'] ?? null,
                    'data' => $result['data'] ?? [],
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Payment Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'data' => $request->all(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar pago',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Post(
     *     path="/api/efevoopay/refund",
     *     summary="Realizar un reembolso",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"transaction_id"},
     *             @OA\Property(property="transaction_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Reembolso procesado exitosamente"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Transacción no encontrada"
     *     )
     * )
     */
    public function refund(RefundRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $user = $request->user();
            
            // Verificar que la transacción pertenezca al usuario
            $transaction = \App\Models\EfevooTransaction::find($data['transaction_id']);
            
            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transacción no encontrada',
                ], 404);
            }
            
            if ($transaction->token && $transaction->token->client_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para reembolsar esta transacción',
                ], 403);
            }
            
            $result = $this->efevooPayService->refundTransaction($data['transaction_id']);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Reembolso procesado exitosamente',
                    'refund_id' => $result['refund_id'] ?? null,
                    'original_transaction_id' => $result['original_transaction_id'] ?? null,
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Refund Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'transaction_id' => $request->input('transaction_id'),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar reembolso',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Post(
     *     path="/api/efevoopay/transactions/search",
     *     summary="Buscar transacciones",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="transaction_id", type="integer", example=459470),
     *             @OA\Property(property="start_date", type="string", format="date-time", example="2025-01-01 00:00:00"),
     *             @OA\Property(property="end_date", type="string", format="date-time", example="2025-12-31 23:59:59")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transacciones encontradas"
     *     )
     * )
     */
    public function searchTransactions(SearchTransactionsRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            
            $result = $this->efevooPayService->searchTransactions($data);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'count' => count($result['data']['data'] ?? []),
                    'transactions' => $result['data']['data'] ?? [],
                    'data' => $result['data'],
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Search Transactions Error', [
                'error' => $e->getMessage(),
                'filters' => $request->all(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar transacciones',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Get(
     *     path="/api/efevoopay/tokens",
     *     summary="Obtener tokens del usuario",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Tokens obtenidos"
     *     )
     * )
     */
    public function getUserTokens(): JsonResponse
    {
        try {
            $user = request()->user();
            
            $tokens = \App\Models\EfevooToken::where('client_id', $user->id)
                ->active()
                ->with(['transactions' => function($query) {
                    $query->latest()->take(5);
                }])
                ->get()
                ->map(function($token) {
                    return [
                        'id' => $token->id,
                        'card_last_four' => $token->card_last_four,
                        'card_brand' => $token->card_brand,
                        'card_expiration' => $token->card_expiration,
                        'card_holder' => $token->card_holder,
                        'is_active' => $token->is_active,
                        'expires_at' => $token->expires_at,
                        'created_at' => $token->created_at,
                        'recent_transactions' => $token->transactions->map(function($transaction) {
                            return [
                                'id' => $transaction->id,
                                'amount' => $transaction->amount,
                                'status' => $transaction->status,
                                'reference' => $transaction->reference,
                                'created_at' => $transaction->created_at,
                            ];
                        }),
                    ];
                });
            
            return response()->json([
                'success' => true,
                'count' => $tokens->count(),
                'tokens' => $tokens,
            ]);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Get User Tokens Error', [
                'error' => $e->getMessage(),
                'user_id' => request()->user()->id,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tokens',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * @OA\Delete(
     *     path="/api/efevoopay/tokens/{token}",
     *     summary="Eliminar token de tarjeta",
     *     tags={"EfevooPay"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token eliminado"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Token no encontrado"
     *     )
     * )
     */
    public function deleteToken($tokenId): JsonResponse
    {
        try {
            $user = request()->user();
            
            $token = \App\Models\EfevooToken::where('client_id', $user->id)
                ->find($tokenId);
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token no encontrado',
                ], 404);
            }
            
            $token->update(['is_active' => false]);
            
            return response()->json([
                'success' => true,
                'message' => 'Token desactivado exitosamente',
            ]);
            
        } catch (\Exception $e) {
            Log::error('EfevooPay Delete Token Error', [
                'error' => $e->getMessage(),
                'user_id' => request()->user()->id,
                'token_id' => $tokenId,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar token',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}