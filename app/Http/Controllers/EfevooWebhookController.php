<?php

namespace App\Http\Controllers;

use App\Models\EfevooTransaction;
use App\Models\EfevooToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class EfevooWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        $signature = $request->header('X-Efevoo-Signature');
        
        Log::info('EfevooPay Webhook recibido', [
            'event' => $payload['event'] ?? 'unknown',
            'transaction_id' => $payload['transaction_id'] ?? null,
        ]);
        
        // Validar firma (implementar según documentación de EfevooPay)
        if (!$this->validateSignature($signature, $payload)) {
            Log::warning('Firma de webhook inválida', ['payload' => $payload]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        $event = $payload['event'] ?? null;
        
        switch ($event) {
            case 'transaction.approved':
                $this->handleTransactionApproved($payload);
                break;
                
            case 'transaction.declined':
                $this->handleTransactionDeclined($payload);
                break;
                
            case 'token.created':
                $this->handleTokenCreated($payload);
                break;
                
            case 'token.deleted':
                $this->handleTokenDeleted($payload);
                break;
                
            case 'refund.processed':
                $this->handleRefundProcessed($payload);
                break;
                
            default:
                Log::warning('Evento de webhook no manejado', ['event' => $event]);
        }
        
        return response()->json(['status' => 'received']);
    }
    
    protected function handleTransactionApproved(array $payload)
    {
        $transactionId = $payload['transaction_id'] ?? null;
        $reference = $payload['reference'] ?? null;
        
        if ($transactionId) {
            $transaction = EfevooTransaction::where('transaction_id', $transactionId)->first();
            
            if ($transaction) {
                $transaction->update([
                    'status' => 'approved',
                    'response_code' => $payload['response_code'] ?? null,
                    'response_message' => $payload['message'] ?? null,
                    'response_data' => $payload,
                    'processed_at' => now(),
                ]);
                
                Log::info('Transacción aprobada actualizada', [
                    'transaction_id' => $transactionId,
                    'reference' => $reference,
                ]);
            }
        }
    }
    
    protected function handleTransactionDeclined(array $payload)
    {
        $transactionId = $payload['transaction_id'] ?? null;
        
        if ($transactionId) {
            $transaction = EfevooTransaction::where('transaction_id', $transactionId)->first();
            
            if ($transaction) {
                $transaction->update([
                    'status' => 'declined',
                    'response_code' => $payload['response_code'] ?? null,
                    'response_message' => $payload['message'] ?? null,
                    'response_data' => $payload,
                    'processed_at' => now(),
                ]);
            }
        }
    }
    
    protected function validateSignature($signature, $payload)
    {
        // Implementar validación de firma según documentación de EfevooPay
        // Por ahora, aceptar todas las solicitudes en ambiente de prueba
        if (config('efevoopay.environment') === 'test') {
            return true;
        }
        
        // En producción, implementar validación real
        $secret = config("efevoopay.production.api_key");
        $expectedSignature = hash_hmac('sha256', json_encode($payload), $secret);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    // Otros métodos de manejo de eventos...
}