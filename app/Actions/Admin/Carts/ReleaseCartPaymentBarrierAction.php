<?php

namespace App\Actions\Admin\Carts;

use App\Enums\CartEventType;
use App\Models\Cart;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Carts\CartEventRecorder;
use App\Services\Carts\CartPaymentBarrierInspector;
use App\Services\Laboratory\UncertainGdaPaymentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseCartPaymentBarrierAction
{
    public function __construct(
        private CartPaymentBarrierInspector $inspector,
        private UncertainGdaPaymentGuard $guard,
        private CartEventRecorder $cartEventRecorder,
    ) {
    }

    /**
     * @return array{released: list<string>, payment_barrier: array<string, mixed>}
     */
    public function __invoke(Cart $cart, User $admin, ?string $note = null): array
    {
        $snapshot = $this->inspector->inspect($cart);

        if (($snapshot['blocked'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'cart' => 'Este carrito no tiene un bloqueo de pago activo.',
            ]);
        }

        if (($snapshot['can_release'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'cart' => 'Hay barreras que requieren conciliacion manual; no se puede liberar automaticamente.',
            ]);
        }

        $released = [];

        DB::transaction(function () use ($cart, $admin, $note, $snapshot, &$released) {
            foreach ($snapshot['barriers'] as $barrier) {
                if (($barrier['releasable'] ?? false) !== true) {
                    continue;
                }

                $released[] = match ($barrier['entity_type'] ?? null) {
                    'payment_attempt' => $this->releasePaymentAttempt(
                        (int) $barrier['entity_id'],
                        $cart,
                        $admin,
                        $note,
                    ),
                    'transaction' => $this->releaseTransaction(
                        (int) $barrier['entity_id'],
                        $cart,
                    ),
                    default => null,
                };
            }

            $released = array_values(array_filter($released));

            if ($released === []) {
                throw ValidationException::withMessages([
                    'cart' => 'No se aplicaron cambios; vuelve a cargar el detalle e intenta de nuevo.',
                ]);
            }

            $this->guard->ensureNoUnresolvedCapture(Cart::query()->useWritePdo()->findOrFail($cart->id));
        });

        $freshCart = Cart::query()->findOrFail($cart->id);

        $this->cartEventRecorder->record(
            $freshCart,
            CartEventType::PaymentBarrierReleased,
            [
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'note' => $note,
                'released' => $released,
                'barriers_before' => $snapshot['barriers'],
            ],
            source: 'admin',
        );

        return [
            'released' => $released,
            'payment_barrier' => $this->inspector->inspect($freshCart),
        ];
    }

    private function releasePaymentAttempt(int $attemptId, Cart $cart, User $admin, ?string $note): string
    {
        $attempt = PaymentAttempt::query()
            ->whereKey($attemptId)
            ->where('cart_id', $cart->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($attempt->status, [
            PaymentAttempt::STATUS_ERROR,
            PaymentAttempt::STATUS_PENDING,
            PaymentAttempt::STATUS_PROCESSING,
        ], true)) {
            throw ValidationException::withMessages([
                'cart' => 'El intento de pago #'.$attemptId.' ya no se puede liberar.',
            ]);
        }

        $suffix = '[Admin '.$admin->id.' libero barrera'.($note ? ': '.$note : '').']';
        $attempt->update([
            'status' => PaymentAttempt::STATUS_DECLINED,
            'processor_message' => trim(((string) ($attempt->processor_message ?? '')).' '.$suffix),
            'processed_at' => now(),
        ]);

        return 'payment_attempt:'.$attempt->id;
    }

    private function releaseTransaction(int $transactionId, Cart $cart): string
    {
        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->where('details->cart_id', $cart->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($transaction->refunded_at !== null || $transaction->isSuccessfulPayment()) {
            throw ValidationException::withMessages([
                'cart' => 'La transaccion #'.$transactionId.' ya tiene un cobro registrado.',
            ]);
        }

        $details = is_array($transaction->details) ? $transaction->details : [];
        if (! isset($details['laboratory_capture_started_at']) && ! isset($details['laboratory_charge_started_at'])) {
            throw ValidationException::withMessages([
                'cart' => 'La transaccion #'.$transactionId.' ya no tiene una barrera activa.',
            ]);
        }

        unset($details['laboratory_capture_started_at'], $details['laboratory_charge_started_at']);
        $details['payment_barrier_released_at'] = now()->toIso8601String();

        $transaction->update([
            'details' => $details,
            'payment_status' => in_array($transaction->payment_status, ['pending', 'processing', null], true)
                ? 'failed'
                : $transaction->payment_status,
        ]);

        return 'transaction:'.$transaction->id;
    }
}
