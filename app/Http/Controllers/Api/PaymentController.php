<?php
// app/Http/Controllers/Api/PaymentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EfevooPayService;
use App\Jobs\ProcessEfevooPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function tokenize(Request $request, EfevooPayService $efevooService)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required|string|size:16',
            'expiry' => 'required|string|size:4|regex:/^[0-9]{4}$/',
            'amount' => 'required|numeric|min:0.01|max:2.00',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $efevooService->tokenizeCard(
                $request->card_number,
                $request->expiry,
                $request->amount
            );

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'message' => $result['message'] ?? null,
                'token_id' => $result['token_id'] ?? null,
                'code' => $result['error_code'] ?? null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al tokenizar tarjeta',
            ], 500);
        }
    }

    public function process(Request $request, EfevooPayService $efevooService)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required_without:card_token|string|size:16',
            'card_token' => 'required_without:card_number|string',
            'expiry' => 'required_if:card_number,!=,null|string|size:4',
            'cvv' => 'required_if:card_number,!=,null|string|size:3',
            'amount' => 'required|numeric|min:0.01|max:2.00',
            'cav' => 'required|string|min:8|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->has('card_token')) {
                $result = $efevooService->tokenPayment(
                    cardToken: $request->card_token,
                    amount: $request->amount,
                    cav: $request->cav,
                    referencia: 'Pago desde API'
                );
            } else {
                $result = $efevooService->simplePayment(
                    cardNumber: $request->card_number,
                    expiry: $request->expiry,
                    cvv: $request->cvv,
                    amount: $request->amount,
                    cav: $request->cav,
                    referencia: 'Pago desde API'
                );
            }

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'message' => $result['message'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
                'code' => $result['error_code'] ?? null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar pago',
            ], 500);
        }
    }

    public function processAsync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required_without:card_token|string|size:16',
            'card_token' => 'required_without:card_number|string',
            'expiry' => 'required_if:card_number,!=,null|string|size:4',
            'cvv' => 'required_if:card_number,!=,null|string|size:3',
            'amount' => 'required|numeric|min:0.01|max:2.00',
            'cav' => 'required|string|min:8|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $paymentData = $request->all();
        $operation = $request->has('card_token') ? 'token_payment' : 'payment';

        // Despachar job para procesamiento asíncrono
        ProcessEfevooPayment::dispatch($paymentData, $operation);

        return response()->json([
            'success' => true,
            'message' => 'Pago en procesamiento',
            'job_id' => uniqid(),
        ]);
    }
}
