<?php

namespace App\Http\Controllers;

use App\Actions\PayPal\CapturePayPalOrderAction;
use App\Actions\PayPal\CreatePayPalOrderAction;
use App\Actions\PayPal\HandlePayPalWebhookAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\CouponApplicationException;
use App\Exceptions\PromoCodeException;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Exceptions\PayPalPaymentException;
use App\Models\Address;
use App\Models\Contact;
use App\Services\CouponApplicationService;
use App\Services\PayPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayPalController extends Controller
{
    public function createOrder(Request $request, CreatePayPalOrderAction $action, CouponApplicationService $couponApplicationService): JsonResponse
    {
        $customer = $request->user()->customer;

        $validated = $request->validate([
            'patient_id' => ['nullable', 'exists:contacts,id'],
            'address_id' => ['required', 'exists:addresses,id'],
            'laboratory_brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'total' => ['required', 'integer', 'min:1'],
            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],
            'promo_validation_token' => ['nullable', 'string', 'max:64'],
        ]);

        $brandRaw = $validated['laboratory_brand'];
        $brand = $brandRaw instanceof LaboratoryBrand
            ? $brandRaw
            : LaboratoryBrand::from((string) $brandRaw);

        if (!$customer->getHasLaboratoryCartItemRequiringAppointment($brand) && empty($validated['patient_id'])) {
            throw ValidationException::withMessages(['patient_id' => 'Selecciona un paciente.']);
        }

        $address = Address::find($validated['address_id']);
        if (!$address || $address->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['address_id' => 'Dirección no válida.']);
        }

        $contact = null;
        if (!empty($validated['patient_id'])) {
            $contact = Contact::find($validated['patient_id']);
            if (!$contact || $contact->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['patient_id' => 'Paciente no válido.']);
            }
        }

        try {
            $couponId = !empty($validated['coupon_id']) ? (int) $validated['coupon_id'] : null;
            $promoValidationToken = !empty($validated['promo_validation_token'])
                ? (string) $validated['promo_validation_token']
                : null;

            if ($couponId !== null && $promoValidationToken !== null) {
                throw ValidationException::withMessages([
                    'promo_validation_token' => 'No puedes combinar un crédito asignado con un código promocional.',
                ]);
            }

            if ($couponId !== null) {
                $couponApplicationService->validateApplication(
                    $request->user(),
                    $couponId,
                    (int) $validated['total']
                );
            }

            $result = $action(
                $customer,
                $address,
                $contact,
                $brand,
                (int) $validated['total'],
                $couponId,
                $promoValidationToken,
            );
        } catch (MissingLaboratoryAppointmentException $e) {
            throw ValidationException::withMessages(['patient_id' => 'Debes completar la cita en laboratorio para este pedido.']);
        } catch (UnmatchingTotalPriceException $e) {
            throw ValidationException::withMessages(['total' => 'El total no coincide con el carrito. Actualiza la página.']);
        } catch (CouponApplicationException $e) {
            throw ValidationException::withMessages(['coupon_id' => $e->getMessage()]);
        } catch (PromoCodeException $e) {
            throw ValidationException::withMessages(['promo_validation_token' => $e->getMessage()]);
        } catch (PayPalPaymentException $e) {
            Log::warning('[PayPal] create-order rechazado por API', ['message' => $e->getMessage()]);

            return response()->json([
                'message' => app()->environment('local')
                    ? $e->getMessage()
                    : 'PayPal no está disponible en este momento. Si el problema continúa, revisa la configuración de credenciales.',
            ], 503);
        }

        Log::info('[PayPal] create-order OK', [
            'user_id' => $request->user()->id,
            'order_id' => $result['order_id'],
        ]);

        return response()->json([
            'order_id' => $result['order_id'],
            'transaction_id' => $result['transaction_id'],
        ]);
    }

    public function captureOrder(Request $request, CapturePayPalOrderAction $action): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string'],
        ]);

        $result = $action($validated['order_id'], $request->user()->customer);

        $status = $result['status'];
        $purchase = $result['purchase'] ?? null;

        if (in_array($status, ['not_found', 'forbidden'], true)) {
            return response()->json([
                'status' => $status,
                'message' => $result['message'] ?? null,
            ], 404);
        }

        if ($status === 'failed' || $status === 'error' || $status === 'invalid_capture') {
            return response()->json([
                'status' => $status,
                'message' => $result['message'] ?? 'No se pudo completar el pago.',
            ], 422);
        }

        session()->flash('confetti', true);

        return response()->json([
            'status' => $status,
            'laboratory_purchase_id' => $purchase?->id,
        ]);
    }

    public function webhook(Request $request, PayPalService $payPalService, HandlePayPalWebhookAction $handlePayPalWebhookAction): JsonResponse
    {
        $payload = $request->all();

        Log::info('[PayPal] Webhook raw recibido', [
            'event_type' => $payload['event_type'] ?? null,
        ]);

        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];

        if (!$payPalService->verifyWebhookSignature($payload, $headers)) {
            Log::warning('[PayPal] Webhook firma no verificada');

            return response()->json(['status' => 'ignored'], 400);
        }

        try {
            $handlePayPalWebhookAction($payload);
        } catch (\Throwable $e) {
            Log::error('[PayPal] Webhook handler error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }
}
