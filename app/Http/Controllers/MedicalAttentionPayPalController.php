<?php

namespace App\Http\Controllers;

use App\Actions\PayPal\CaptureMedicalAttentionPayPalOrderAction;
use App\Actions\PayPal\CreateMedicalAttentionPayPalOrderAction;
use App\Exceptions\PayPalPaymentException;
use App\Exceptions\PayPalRecoveryConfirmationPendingException;
use App\Support\PaymentAuthenticationRecoveryStartException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MedicalAttentionPayPalController extends Controller
{
    public function createOrder(Request $request, CreateMedicalAttentionPayPalOrderAction $action): JsonResponse
    {
        $customer = $request->user()->customer;
        $validated = $request->validate([
            'recovery_context_uuid' => ['nullable', 'uuid'],
        ]);

        if ($customer->medicalAttentionSubscriptions()->active()->exists()) {
            return response()->json([
                'message' => 'Ya tienes una membresía médica activa.',
            ], 403);
        }

        try {
            $result = $action($customer, $validated['recovery_context_uuid'] ?? null);
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
        } catch (PaymentAuthenticationRecoveryStartException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->error,
            ], $e->status);
        } catch (PayPalRecoveryConfirmationPendingException $e) {
            Log::warning('[PayPal][MedicalAttention] create-order confirmación pendiente', [
                'customer_id' => $customer->id,
                'support_reference' => $e->supportReference,
            ]);

            return response()->json($e->toArray(), $e->httpStatus);
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

        if (in_array($status, ['failed', 'error', 'invalid_capture', 'confirmation_pending'], true)) {
            $httpStatus = $status === 'confirmation_pending' ? 503 : 422;

            return response()->json(array_filter([
                'status' => $status,
                'message' => $result['message'] ?? 'No se pudo completar el pago.',
                'error' => $result['error'] ?? null,
                'support_reference' => $result['support_reference'] ?? null,
            ]), $httpStatus);
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
