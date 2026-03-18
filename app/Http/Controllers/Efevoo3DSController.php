<?php

namespace App\Http\Controllers;

use App\Models\Efevoo3dsSession;
use App\Services\EfevooPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class Efevoo3DSController extends Controller
{
    protected EfevooPayService $efevooService;

    public function __construct(EfevooPayService $efevooService)
    {
        $this->efevooService = $efevooService;
    }

    /* ==========================================================
     * 1️⃣ INICIAR 3DS
     * ========================================================== */

    public function initiate3DS(Request $request)
    {
        $validated = $request->validate([
            'card_number'  => 'required|string|size:16',
            'expiration'   => 'required|string|size:4', // MMYY
            'cvv'          => 'required|string|min:3|max:4',
            'card_holder'  => 'required|string|max:100',
            'amount'       => 'required|numeric|min:0.01',
        ]);

        try {

            $cardData = [
                'card_number' => $validated['card_number'],
                'expiration'  => $validated['expiration'],
                'cvv'         => $validated['cvv'],
                'card_holder' => $validated['card_holder'],
                'amount'      => $validated['amount'],
            ];

            // Guardamos datos temporalmente en sesión
            Session::put('3ds_card_data', $cardData);

            $result = $this->efevooService->initiate3DS(
                $cardData,
                auth()->id()
            );

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            return response()->json([
                'success'      => true,
                'session_id'   => $result['session_id'],
                'url_3dsecure' => $result['url_3dsecure'],
                'token_3dsecure' => $result['token_3dsecure'],
            ]);

        } catch (\Throwable $e) {

            Log::error('[3DS] initiate error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error iniciando verificación 3DS'
            ], 500);
        }
    }

    /* ==========================================================
     * 2️⃣ CALLBACK DEL BANCO (REDIRECCIÓN REAL)
     * ========================================================== */

    public function handleCallback(Request $request)
    {
        Log::info('[3DS] Callback recibido', $request->all());

        try {

            $orderId = $request->input('order_id');

            if (!$orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order ID no recibido'
                ], 400);
            }

            $session = Efevoo3dsSession::where('order_id', $orderId)->firstOrFail();

            $cardData = Session::get('3ds_card_data');

            if (!$cardData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de tarjeta no encontrados en sesión'
                ], 400);
            }

            $result = $this->efevooService->complete3DS($session, $cardData);

            return redirect()->route('payment-methods.3ds-result', [
                'sessionId' => $session->id
            ]);

        } catch (\Throwable $e) {

            Log::error('[3DS] Callback error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando callback 3DS'
            ], 500);
        }
    }

    /* ==========================================================
     * 3️⃣ VER RESULTADO
     * ========================================================== */

    public function showResult($sessionId)
    {
        $session = Efevoo3dsSession::findOrFail($sessionId);

        return response()->json([
            'success'        => $session->status === 'completed',
            'status'         => $session->status,
            'card_last_four' => $session->card_last_four,
            'amount'         => $session->amount,
            'created_at'     => $session->created_at,
        ]);
    }

    /* ==========================================================
     * 4️⃣ REFUND
     * ========================================================== */

    public function refundTransaction(Request $request, $transactionId)
    {
        try {

            $result = $this->efevooService->refundTransaction((int)$transactionId);

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Reembolso procesado correctamente',
                'data'    => $result['data'] ?? null,
            ]);

        } catch (\Throwable $e) {

            Log::error('[Refund] Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando reembolso'
            ], 500);
        }
    }
}