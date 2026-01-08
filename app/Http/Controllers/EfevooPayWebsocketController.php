<?php

namespace App\Http\Controllers;

use App\Services\WebSocketService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EfevooPayWebsocketController extends Controller
{
    /**
     * Endpoint para recibir notificaciones WebSocket simuladas
     * (Cuando EfevooPay no puede conectar directamente a nuestro WebSocket)
     */
    public function handleNotification(Request $request): JsonResponse
    {
        Log::info('Notificación WebSocket EfevooPay recibida', [
            'method' => $request->method(),
            'payload' => $request->all(),
            'headers' => $request->headers->all(),
        ]);
        
        // Validar payload básico
        $validator = Validator::make($request->all(), [
            'status.code' => 'required|string',
            'payload.message' => 'nullable|string',
            'payload.token' => 'nullable|string',
            'payload.status' => 'nullable|string|in:approved,declined,pending',
        ]);
        
        if ($validator->fails()) {
            Log::warning('Payload de notificación inválido', [
                'errors' => $validator->errors()->toArray(),
                'payload' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Payload inválido',
                'errors' => $validator->errors(),
            ], 400);
        }
        
        try {
            $webSocketService = app(WebSocketService::class);
            $webSocketService->processEfevooPayNotification($request->all());
            
            Log::info('Notificación procesada exitosamente', [
                'payload_token' => $request->input('payload.token'),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Notificación procesada',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error procesando notificación WebSocket', [
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno procesando notificación',
            ], 500);
        }
    }
    
    /**
     * Obtener canales de WebSocket para un usuario
     */
    public function getChannels(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }
        
        // Obtener transacciones pendientes del usuario
        $pendingTransactions = $user->customer->transactions()
            ->where('gateway', 'efevoopay')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('gateway_processed_at')
            ->get();
        
        $channels = [
            'user_channel' => 'private-user.' . $user->id,
        ];
        
        foreach ($pendingTransactions as $transaction) {
            $channels['payment_channels'][] = [
                'transaction_id' => $transaction->id,
                'channel' => 'private-payment.' . $transaction->id,
                'created_at' => $transaction->created_at->toISOString(),
            ];
        }
        
        return response()->json([
            'status' => 'success',
            'channels' => $channels,
            'pusher_config' => [
                'key' => config('broadcasting.connections.pusher.key'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster', 'mt1'),
                'wsHost' => config('broadcasting.connections.pusher.options.host', '127.0.0.1'),
                'wsPort' => config('broadcasting.connections.pusher.options.port', 6001),
            ],
        ]);
    }
    
    /**
     * Autenticar suscripción a canal privado
     */
    public function authenticateChannel(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }
        
        $channelName = $request->input('channel_name');
        
        // Validar que el usuario puede acceder a este canal
        if (str_starts_with($channelName, 'private-user.')) {
            $requestedUserId = str_replace('private-user.', '', $channelName);
            
            if ($requestedUserId != $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado al canal',
                ], 403);
            }
            
            // Autorizar acceso
            $pusher = app('pusher');
            $auth = $pusher->socket_auth($channelName, $request->input('socket_id'));
            
            return response()->json(json_decode($auth));
            
        } elseif (str_starts_with($channelName, 'private-payment.')) {
            $transactionId = str_replace('private-payment.', '', $channelName);
            
            // Verificar que la transacción pertenece al usuario
            $transaction = $user->customer->transactions()
                ->where('id', $transactionId)
                ->first();
            
            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transacción no encontrada o no autorizada',
                ], 403);
            }
            
            // Autorizar acceso
            $pusher = app('pusher');
            $auth = $pusher->socket_auth($channelName, $request->input('socket_id'));
            
            return response()->json(json_decode($auth));
        }
        
        return response()->json([
            'status' => 'error',
            'message' => 'Canal no válido',
        ], 400);
    }
}