<?php

namespace App\Services\Carts;

use App\Models\Cart;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Services\Laboratory\UncertainGdaPaymentGuard;
use App\Services\PayPalService;

class CartPaymentBarrierInspector
{
    public function __construct(
        private UncertainGdaPaymentGuard $guard,
        private PayPalService $payPalService,
    ) {
    }

    /**
     * @return array{
     *     blocked: bool,
     *     summary: ?string,
     *     can_release: bool,
     *     barriers: list<array<string, mixed>>
     * }
     */
    public function inspect(Cart $cart): array
    {
        $barriers = [];

        if ($purchase = $this->guard->capturedUncertainPurchaseForCart($cart)) {
            $barriers[] = $this->barrier(
                code: 'paid_uncertain_purchase',
                label: 'Pago recibido en revision',
                detail: 'Compra #'.$purchase->id.' ligada a un cobro exitoso; no se puede liberar el checkout para un nuevo pago.',
                releasable: false,
                entityType: 'laboratory_purchase',
                entityId: $purchase->id,
            );
        }

        foreach ($this->blockingPaymentAttempts($cart) as $attempt) {
            $releasable = $this->paymentAttemptReleasable($attempt);
            $barriers[] = $this->barrier(
                code: 'payment_attempt_'.$attempt->status,
                label: $this->paymentAttemptLabel($attempt),
                detail: $this->paymentAttemptDetail($attempt),
                releasable: $releasable,
                entityType: 'payment_attempt',
                entityId: $attempt->id,
                extra: [
                    'gateway' => $attempt->gateway,
                    'status' => $attempt->status,
                ],
            );
        }

        foreach ($this->blockingTransactions($cart) as $transaction) {
            $releasable = $this->transactionBarrierReleasable($transaction);
            $barriers[] = $this->barrier(
                code: 'transaction_'.$this->transactionBarrierCode($transaction),
                label: $this->transactionBarrierLabel($transaction),
                detail: $this->transactionBarrierDetail($transaction, $releasable),
                releasable: $releasable,
                entityType: 'transaction',
                entityId: $transaction->id,
                extra: [
                    'payment_method' => $transaction->payment_method,
                    'payment_status' => $transaction->payment_status,
                ],
            );
        }

        $blocked = $barriers !== [];
        $canRelease = $blocked && collect($barriers)->every(fn (array $barrier) => ($barrier['releasable'] ?? false) === true);

        return [
            'blocked' => $blocked,
            'summary' => $blocked ? $this->summarize($barriers) : null,
            'can_release' => $canRelease,
            'barriers' => $barriers,
        ];
    }

    public function wouldBlockCheckout(Cart $cart): bool
    {
        try {
            $this->guard->ensureNoUnresolvedCapture($cart);

            return false;
        } catch (\App\Exceptions\LaboratoryPaymentInProgressException) {
            return true;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, PaymentAttempt>
     */
    private function blockingPaymentAttempts(Cart $cart)
    {
        return PaymentAttempt::query()
            ->where('cart_id', $cart->id)
            ->whereIn('status', [
                PaymentAttempt::STATUS_PENDING,
                PaymentAttempt::STATUS_PROCESSING,
                PaymentAttempt::STATUS_APPROVED,
                PaymentAttempt::STATUS_ERROR,
            ])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.reference_id', 'payment_attempts.reference')
                    ->whereNotNull('transactions.refunded_at');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function blockingTransactions(Cart $cart)
    {
        return Transaction::query()
            ->where('details->cart_id', $cart->id)
            ->whereNull('refunded_at')
            ->where(function ($query) {
                $query->whereNotNull('details->laboratory_capture_started_at')
                    ->orWhereNotNull('details->laboratory_charge_started_at');
            })
            ->orderBy('id')
            ->get();
    }

    private function paymentAttemptReleasable(PaymentAttempt $attempt): bool
    {
        if ($attempt->status === PaymentAttempt::STATUS_APPROVED) {
            return false;
        }

        if ($this->hasSuccessfulTransactionForAttempt($attempt)) {
            return false;
        }

        return in_array($attempt->status, [
            PaymentAttempt::STATUS_ERROR,
            PaymentAttempt::STATUS_PENDING,
            PaymentAttempt::STATUS_PROCESSING,
        ], true);
    }

    private function hasSuccessfulTransactionForAttempt(PaymentAttempt $attempt): bool
    {
        if (! filled($attempt->reference)) {
            return false;
        }

        return Transaction::query()
            ->where('reference_id', $attempt->reference)
            ->whereNull('refunded_at')
            ->where(function ($query) {
                $query->whereIn('payment_status', ['approved', 'captured', 'completed', 'paid', 'succeeded'])
                    ->orWhereIn('gateway_status', ['APPROVED', 'CAPTURED', 'COMPLETED', 'approved', 'captured', 'completed']);
            })
            ->exists();
    }

    private function transactionBarrierReleasable(Transaction $transaction): bool
    {
        if ($transaction->refunded_at !== null) {
            return false;
        }

        if ($transaction->isSuccessfulPayment()) {
            return false;
        }

        if ($transaction->laboratoryPurchases()->exists() && $transaction->payment_status === 'captured') {
            return false;
        }

        if ($transaction->payment_method === 'paypal') {
            return ! $this->paypalOrderShowsCapture($transaction);
        }

        return in_array($transaction->payment_status, ['processing', 'pending', 'failed', null], true)
            || blank($transaction->payment_status);
    }

    private function paypalOrderShowsCapture(Transaction $transaction): bool
    {
        $orderId = $transaction->provider_order_id ?: $transaction->reference_id;
        if (! filled($orderId)) {
            return false;
        }

        try {
            $observed = $transaction->payment_status === 'captured' && is_array($transaction->raw_response)
                ? $transaction->raw_response
                : $this->payPalService->getOrder((string) $orderId);
            $info = $this->payPalService->extractCaptureInfo($observed);

            return ($info['capture_id'] ?? null) !== null
                && in_array(strtoupper((string) ($info['status'] ?? '')), ['COMPLETED', 'CAPTURED'], true);
        } catch (\Throwable) {
            return true;
        }
    }

    private function paymentAttemptLabel(PaymentAttempt $attempt): string
    {
        return match ($attempt->status) {
            PaymentAttempt::STATUS_ERROR => 'Intento Efevoo con error tecnico',
            PaymentAttempt::STATUS_DECLINED => 'Intento Efevoo rechazado',
            PaymentAttempt::STATUS_APPROVED => 'Intento Efevoo aprobado sin conciliar',
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'Intento Efevoo pendiente',
            default => 'Intento de pago activo',
        };
    }

    private function paymentAttemptDetail(PaymentAttempt $attempt): string
    {
        $message = filled($attempt->processor_message)
            ? ' ('.$attempt->processor_message.')'
            : '';

        return 'Intento #'.$attempt->id.' bloquea nuevos cobros en este carrito'.$message.'.';
    }

    private function transactionBarrierCode(Transaction $transaction): string
    {
        $details = is_array($transaction->details) ? $transaction->details : [];

        if (isset($details['laboratory_capture_started_at'])) {
            return 'capture_started';
        }

        if (isset($details['laboratory_charge_started_at'])) {
            return 'charge_started';
        }

        return 'in_progress';
    }

    private function transactionBarrierLabel(Transaction $transaction): string
    {
        return match ($transaction->payment_method) {
            'paypal' => 'Captura PayPal interrumpida',
            'odessa' => 'Cargo Odessa en revision',
            default => 'Transaccion de pago en revision',
        };
    }

    private function transactionBarrierDetail(Transaction $transaction, bool $releasable): string
    {
        if ($transaction->payment_method === 'paypal' && ! $releasable) {
            return 'Transaccion #'.$transaction->id.': PayPal reporta captura o no se pudo verificar la orden. Concilia antes de liberar.';
        }

        return 'Transaccion #'.$transaction->id.' ('.($transaction->payment_method ?: 'desconocido').') dejo una barrera de cobro activa.';
    }

    /**
     * @param  list<array<string, mixed>>  $barriers
     */
    private function summarize(array $barriers): string
    {
        if (count($barriers) === 1) {
            return (string) ($barriers[0]['label'] ?? 'Bloqueo de pago');
        }

        return count($barriers).' barreras impiden un nuevo intento de pago.';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function barrier(
        string $code,
        string $label,
        string $detail,
        bool $releasable,
        string $entityType,
        int $entityId,
        array $extra = [],
    ): array {
        return array_merge([
            'code' => $code,
            'label' => $label,
            'detail' => $detail,
            'releasable' => $releasable,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], $extra);
    }
}
