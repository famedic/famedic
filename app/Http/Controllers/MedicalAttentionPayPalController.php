<?php

namespace App\Http\Controllers;

use App\Actions\PayPal\CaptureMedicalAttentionPayPalOrderAction;
use App\Actions\PayPal\CreateMedicalAttentionPayPalOrderAction;
use App\Exceptions\PayPalPaymentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MedicalAttentionPayPalController extends Controller
{
    public function createOrder(Request $request, CreateMedicalAttentionPayPalOrderAction $action): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer->medicalAttentionSubscriptions()->active()->exists()) {
            return response()->json([
                'message' => 'Ya tienes una membresía médica activa.',
            ], 403);
        }

        try {
            $result = $action($customer);
        } catch (PayPalPaymentException $e) {
            Log::warning('[PayPal][MedicalAttention] create-order rechazado', [
                'message' => $e->getMessage(),
                'customer_id' => $customer->id,
            ]);

            return response()->json([
                'message' => app()->environment('local')
                    ? $e->getMessage()
                    : 'PayPal no está disponible en este momento.',
            ], 503);
        }

        Log::info('[PayPal][MedicalAttention] create-order OK', [
            'user_id' => $request->user()->id,
            'order_id' => $result['order_id'],
        ]);

        return response()->json([
            'order_id' => $result['order_id'],
            'transaction_id' => $result['transaction_id'],
        ]);
    }

    public function captureOrder(Request $request, CaptureMedicalAttentionPayPalOrderAction $action): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string'],
        ]);

        $result = $action($validated['order_id'], $request->user()->customer);

        $status = $result['status'];

        if (in_array($status, ['not_found', 'forbidden'], true)) {
            return response()->json([
                'status' => $status,
                'message' => $result['message'] ?? null,
            ], 404);
        }

        if (in_array($status, ['failed', 'error', 'invalid_capture'], true)) {
            return response()->json([
                'status' => $status,
                'message' => $result['message'] ?? 'No se pudo completar el pago.',
            ], 422);
        }

        session()->flash('confetti', true);
        session()->flash('flashMessage', [
            'message' => 'Tu suscripción de atención médica ha comenzado exitosamente.',
            'type' => 'success',
        ]);

        return response()->json([
            'status' => $status,
            'redirect_url' => route('medical-attention'),
        ]);
    }
}
