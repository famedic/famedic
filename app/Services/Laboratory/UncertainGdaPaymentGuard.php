<?php

namespace App\Services\Laboratory;

use App\Exceptions\LaboratoryPaymentAlreadyReceivedException;
use App\Models\Cart;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Models\Customer;
use App\Enums\LaboratoryBrand;
use App\Services\Monitoring\SyncMonitoringCartService;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UncertainGdaPaymentGuard
{
    public function withLock(string $key, Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Laboratory payments require shared MySQL locks.');
        }
        // Connection-scoped, process-shared and reentrant; no expiring cache lease.
        $name = 'lab-payment:'.substr(hash('sha256', $connection->getDatabaseName().':'.$key), 0, 48);
        $result = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name], false);
        if ((int) $result->acquired !== 1) {
            throw new RuntimeException('Laboratory payment is being processed; retry status later.');
        }
        try {
            return $callback();
        } finally {
            try {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
            } catch (\Throwable) {
                \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'laboratory_payment_lock_release_failed', ['error_code' => 'lock_release_failed']);
            }
        }
    }

    /** Called only while holding the cart/transaction lock: pending then means an interrupted attempt. */
    public function existingPurchaseResult(LaboratoryPurchase $purchase): LaboratoryPurchase
    {
        if ($purchase->gda_status === \App\Enums\GdaOrderStatus::Pending) {
            $safe = new \App\Exceptions\GdaOrderResultUncertainException('gda_replay_unresolved', $purchase);
            $purchase->forceFill(['gda_status' => \App\Enums\GdaOrderStatus::Uncertain,
                'has_gda_warning' => true, 'gda_warning_message' => $safe->getMessage()]);
            try { $purchase->save(); }
            catch (\Throwable) { \App\Services\Laboratory\GdaPaymentDiagnostics::record('error', 'gda_pending_recovery_persistence_failed', $safe->context()); }
        }
        return $purchase;
    }

    public function cartForTransaction(Transaction $transaction): ?Cart
    {
        $details = is_array($transaction->details) ? $transaction->details : [];
        if (isset($details['cart_id'])) {
            return Cart::query()->useWritePdo()->find($details['cart_id']);
        }
        $customer = Customer::find($details['customer_id'] ?? null);
        $brand = LaboratoryBrand::tryFrom((string) ($details['laboratory_brand'] ?? ''));
        return $customer && $brand ? app(SyncMonitoringCartService::class)->activeLaboratoryCart($customer, $brand) : null;
    }

    public function withTransactionLock(Transaction $transaction, Closure $callback): mixed
    {
        $cart = $this->cartForTransaction($transaction);
        $locked = fn () => $this->withLock('transaction:'.$transaction->id, $callback);
        return $cart ? $this->withLock('cart:'.$cart->id, $locked) : $locked();
    }

    public function withCartLock(?Cart $cart, Closure $callback): mixed
    {
        if (! $cart) {
            throw new RuntimeException('Cannot initiate laboratory payment without a traceable cart.');
        }
        return $this->withLock('cart:'.$cart->id, function () use ($cart, $callback) {
            $fresh = Cart::query()->useWritePdo()->findOrFail($cart->id);
            $this->ensureNoCapturedUncertainPurchase($fresh);
            $this->ensureNoUnresolvedCapture($fresh);
            return $callback($fresh);
        });
    }

    public function ensureNoCapturedUncertainPurchase(?Cart $cart): void
    {
        if ($purchase = $this->capturedUncertainPurchaseForCart($cart)) {
            throw new LaboratoryPaymentAlreadyReceivedException($this->existingPurchaseResult($purchase));
        }
    }

    public function capturedUncertainPurchaseForCart(?Cart $cart): ?LaboratoryPurchase
    {
        if (! $cart) {
            return null;
        }
        // Confirmed and pending attempts also block recapture of the same cart.
        return LaboratoryPurchase::query()->useWritePdo()->where('cart_id', $cart->id)
            ->whereHas('transactions', fn ($query) => $this->successfulTransactionScope($query))
            ->latest('id')->first();
    }

    public function ensureNoUnresolvedCapture(Cart $cart, ?int $ownTransactionId = null): void
    {
        $unresolvedAttempt = \App\Models\PaymentAttempt::query()->useWritePdo()->where('cart_id', $cart->id)
            ->whereIn('status', ['pending', 'processing', 'approved', 'error'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('transactions')
                ->whereColumn('transactions.reference_id', 'payment_attempts.reference')->whereNotNull('transactions.refunded_at'))->exists();
        if ($unresolvedAttempt) {
            throw new \App\Exceptions\LaboratoryPaymentInProgressException;
        }
        if (Transaction::query()->useWritePdo()->where('details->cart_id', $cart->id)->whereNull('refunded_at')
            ->where(fn ($query) => $query->whereNotNull('details->laboratory_capture_started_at')->orWhereNotNull('details->laboratory_charge_started_at'))
            ->when($ownTransactionId !== null, fn ($query) => $query->where('id', '!=', $ownTransactionId))->exists()) {
            throw new \App\Exceptions\LaboratoryPaymentInProgressException;
        }
    }

    public function successfulTransactionScope($query)
    {
        return $query->whereNull('refunded_at')->where(function ($status) {
            $status->whereIn('payment_status', ['approved', 'captured', 'completed', 'paid', 'succeeded'])
                ->orWhereIn('gateway_status', ['APPROVED', 'CAPTURED', 'COMPLETED', 'approved', 'captured', 'completed'])
                ->orWhereNotNull('gateway_transaction_id')->orWhereNotNull('provider_transaction_id')
                ->orWhere(fn ($odessa) => $odessa->where('payment_method', 'odessa')->whereNotNull('reference_id')
                    ->whereNotIn('reference_id', ['', 'pending']));
        });
    }
}
