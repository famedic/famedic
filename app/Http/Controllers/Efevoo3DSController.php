<?php

namespace App\Http\Controllers;

use App\Models\Efevoo3dsSession;
use App\Services\EfevooPayService;
use App\Support\EfevooPayLogSanitizer;
use App\Support\PaymentAuthenticationSensitiveCardDataStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Legacy 3DS API — routes are commented out in routes/settings.php.
 *             Active flows must use PaymentMethodController + PaymentAuthenticationSensitiveCardDataStore.
 */
class Efevoo3DSController extends Controller
{
    protected EfevooPayService $efevooService;

    public function __construct(EfevooPayService $efevooService)
    {
        $this->efevooService = $efevooService;
    }

    public function initiate3DS(Request $request)
    {
        if (config('efevoopay.sensitive_card_data.containment_enabled', true)) {
            abort(410, 'Legacy 3DS endpoint disabled. Use payment-methods store flow.');
        }

        $validated = $request->validate([
            'card_number'  => 'required|string|size:16',
            'expiration'   => 'required|string|size:4',
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

            Log::warning('[3DS] Legacy initiate3DS invoked — unsuffixed session key is deprecated');

            $result = $this->efevooService->initiate3DS(
                $cardData,
                auth()->id()
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error iniciando verificacion 3DS',
                    'error_type' => $result['error_type'] ?? null,
                ], 400);
            }

            return response()->json([
                'success'      => true,
                'session_id'   => $result['session_id'],
                'url_3dsecure' => $result['url_3dsecure'],
                'token_3dsecure' => $result['token_3dsecure'],
            ]);

        } catch (\Throwable $e) {

            Log::error('[3DS] initiate error', [
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error iniciando verificación 3DS'
            ], 500);
        }
    }

    public function handleCallback(Request $request)
    {
        abort(410, 'Legacy 3DS callback disabled.');
    }

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

    public function refundTransaction(Request $request, $transactionId)
    {
        try {

            $result = $this->efevooService->refundTransaction((int)$transactionId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error procesando reembolso',
                    'code' => $result['error_code'] ?? null,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Reembolso procesado correctamente',
                'data'    => $result['data'] ?? null,
            ]);

        } catch (\Throwable $e) {

            Log::error('[Refund] Error', [
                'transaction_id' => $transactionId,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando reembolso'
            ], 500);
        }
    }
}
