<?php

namespace App\Http\Controllers;

use App\Services\EfevooPayService;
use App\Support\EfevooPayLogSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EfevooPayController extends Controller
{
    public function __construct(
        protected EfevooPayService $efevooPay
    ) {}

    /**
     * Obtener token del cliente
     */
    public function getToken(Request $request)
    {
        try {
            $response = $this->efevooPay->getClientToken();
            
            return response()->json([
                'success' => true,
                'expires_in' => $response['expires_in'] ?? null,
            ]);
            
        } catch (\Exception $e) {
            Log::error('[EfevooPay] getToken failed', EfevooPayLogSanitizer::exception($e));

            return response()->json([
                'success' => false,
                'message' => 'No fue posible obtener el token de cliente.',
            ], 500);
        }
    }

    /**
     * Probar conexión
     */
    public function testConnection()
    {
        $result = $this->efevooPay->testCredentials();
        
        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'] ?? null,
        ]);
    }

    /**
     * Ejemplo: Crear pago
     */
    public function createPayment(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000',
            'reference' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'customer_email' => 'required|email',
        ]);

        $transactionData = [
            'amount' => $validated['amount'],
            'currency' => 'COP',
            'reference' => $validated['reference'],
            'description' => $validated['description'],
            'customer' => [
                'email' => $validated['customer_email'],
                'name' => $request->input('customer_name', 'Cliente'),
            ],
            'return_url' => route('payment.callback'),
            'callback_url' => route('payment.webhook'),
        ];

        try {
            $response = $this->efevooPay->createTransaction($transactionData);
            
            return response()->json([
                'success' => isset($response['payment_url']),
                'payment_url' => $response['payment_url'] ?? null,
                'transaction_id' => $response['transaction_id'] ?? null,
            ]);
            
        } catch (\Exception $e) {
            Log::error('[EfevooPay] createPayment failed', EfevooPayLogSanitizer::exception($e));

            return response()->json([
                'success' => false,
                'message' => 'No fue posible crear el pago.',
            ], 500);
        }
    }
}
